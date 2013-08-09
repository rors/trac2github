<?php
/**
 * @package trac2github
 * @version 1.1
 * @author Vladimir Sibirov
 * @author Lukas Eder
 * @author Rory Solomon
 * @copyright (c) Vladimir Sibirov 2011
 * @license BSD
 */

// set DB type constants for use in config file
define('MYSQL','mysql');
define('SQLITE','sqlite');
define('POSTGRES','postgres');

if (!file_exists("./config.php")) {
	echo "ERROR! Missing configuration file config.php. You can copy it from config.php.sample and edit with your data\n";
	exit;

}
require "./config.php";

// DO NOT EDIT BELOW

error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors', 1);
set_time_limit(0);

// Connect to the trac database, either using SQLite, MySQL or Postgres
if ( $database_type == MYSQL ) {
	$trac_db = new PDO('mysql:host='.$db_host.';port='.$db_port.';dbname='.$db_name.';user='.$db_user.';password='.$db_password.';');
	$Q = '`';
}
 else if ( $database_type == POSTGRES ) {
	$trac_db = new PDO('pgsql:host='.$db_host.';port='.$db_port.';dbname='.$db_name.';user='.$db_user.';password='.$db_password.';');
	$Q = '"';
}
else if ( $database_type == SQLITE ) {
	$trac_db = new PDO('sqlite:' . $sqlitePath);
	$Q = '`';
} else {
	exit("Invalid value '".$database_type."' specified for variable \$database_type\n");
}

echo "Connected to Trac\n";

$milestones = array();
if (file_exists($save_milestones)) {
	$milestones = unserialize(file_get_contents($save_milestones));
}

if (!$skip_milestones) {
	// Export all milestones
	$res = $trac_db->query("SELECT * FROM {$Q}milestone{$Q} ORDER BY {$Q}due{$Q}");
	$rows = set_db_iterator($res);
	$mnum = 1;
	foreach ($rows as $row) {
		//$milestones[$row['name']] = ++$mnum;
		$milestoneData = array(
			'title' => $row['name'],
			'state' => $row['completed'] == 0 ? 'open' : 'closed',
			'description' => empty($row['description']) ? 'None' : $row['description'],
		);
		if ($row['due'] != '0') {
			$milestoneData['due_on'] = date('Y-m-d\TH:i:s\Z', substr ($row['due'], 0, -6));
		}
		$resp = github_add_milestone($milestoneData);
		if (isset($resp['number'])) {
			// OK
			$milestones[crc32($row['name'])] = (int) $resp['number'];
			echo "Milestone {$row['name']} converted to {$resp['number']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert milestone {$row['name']}: $error\n";
			echo "Terminating conversion; please address the problem and retry\n";
			die;
		}
	}
	// Serialize to restore in future
	file_put_contents($save_milestones, serialize($milestones));
}

$labels = array();
$labels['T'] = array();
$labels['C'] = array();
$labels['P'] = array();
$labels['R'] = array();
if (file_exists($save_labels)) {
	$labels = unserialize(file_get_contents($save_labels));
}

if (!$skip_labels) {
    // Export all "labels"
	$res = $trac_db->query("SELECT DISTINCT 'T' label_type, type       name, 'cccccc' color
	                        FROM ticket WHERE IFNULL(type, '')       <> ''
							UNION
							SELECT DISTINCT 'C' label_type, component  name, '0000aa' color
	                        FROM ticket WHERE IFNULL(component, '')  <> ''
							UNION
							SELECT DISTINCT 'P' label_type, priority   name, case when lower(priority) = 'urgent' then 'ff0000'
							                                                      when lower(priority) = 'high'   then 'ff6666'
																				  when lower(priority) = 'medium' then 'ffaaaa'
																				  when lower(priority) = 'low'    then 'ffdddd'
																				  else                                 'aa8888' end color
	                        FROM ticket WHERE IFNULL(priority, '')   <> ''
							UNION
							SELECT DISTINCT 'R' label_type, resolution name, '55ff55' color
	                        FROM ticket WHERE IFNULL(resolution, '') <> ''");

	// Define label name expansions
	$labelTypeNames = array (
		'P' => 'Priority',
		'R' => 'Resolution',
		'T' => 'Type',
	);
	$rows = set_db_iterator($res);
	foreach ($rows as $row) {
		$resp = github_add_label(array(
			'name' => $labelTypeNames[$row['label_type']] . ': ' . $row['name'],
			'color' => $row['color']
		));

		if (isset($resp['url'])) {
			// OK
			$labels[$row['label_type']][crc32($row['name'])] = $resp['name'];
			echo "Label {$row['name']} converted to {$resp['name']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert label {$row['name']}: $error\n";
			echo "Terminating conversion; please address the problem and retry\n";
			die;
		}
	}
	// Serialize to restore in future
	file_put_contents($save_labels, serialize($labels));
}

// Try get previously fetched tickets
$tickets = array();
if (file_exists($save_tickets)) {
	$tickets = unserialize(file_get_contents($save_tickets));
}

// get convert_revision array to replace svn revision with git revision in ticket and ticket comments
if (empty($convert_revision) && !empty($convert_revision_file)) {
	include $convert_revision_file;
	$convert_revision_regexp = array();
	foreach ($convert_revision as $svnRev => $gitRev) {
		$convert_revision_regexp['/\[' . $svnRev . '\]/'] = $gitRev;
	}
}

if (!$skip_tickets) {
	// Export tickets
	$limit = $ticket_limit > 0 ? "LIMIT $ticket_offset, $ticket_limit" : '';
	$resTickets = $trac_db->query("SELECT * FROM {$Q}ticket{$Q} ORDER BY {$Q}id{$Q} $limit");
	$resTicketsAll = set_db_iterator($resTickets);

	$responsesCache = array ();
	foreach ($resTicketsAll as $row) {
		// do not esclude ticket without milestone
		// if (empty($row['milestone'])) {
		// 	continue;
		// }

		$ticketLabels = array();
		if (!empty($labels['T'][crc32($row['type'])])) {
		    $ticketLabels[] = $labels['T'][crc32($row['type'])];
		}
		if (!empty($labels['C'][crc32($row['component'])])) {
		    $ticketLabels[] = $labels['C'][crc32($row['component'])];
		}
		if (!empty($labels['P'][crc32($row['priority'])])) {
		    $ticketLabels[] = $labels['P'][crc32($row['priority'])];
		}
		if (!empty($labels['R'][crc32($row['resolution'])])) {
		    $ticketLabels[] = $labels['R'][crc32($row['resolution'])];
		}

		// if more trac ticket is assigned at more users get the first one (github api v3 dosen't support multi assignee)
		if (empty($row['owner'])) {
			$row['owner'] = $default_username;
		}
		$owners = str_replace(' ', ',', $row['owner']);
		$owners = explode(',', $owners);
		$owner = $owners[0];
		if (isset($users_list[$owner])) {
			$assignee = $users_list[$owner];
		} else {
			$assignee = $default_username;
		}

		// set github username and password to post ticket
		if (isset($users_list[$row['reporter']])) {
			$username = $users_list[$row['reporter']];
			$oauth_token = (isSet ($github_users_oauth_tokens[$username]) ? $github_users_oauth_tokens[$username] : false);
			$password = (isSet ($github_users_passwords[$username]) ? $github_users_passwords[$username] : false);
		} else {
			$username = $default_username;
			$oauth_token = $default_oauth_token;
			$password = $default_password;
		}

		// replace svn revision with git revision
		if (!empty($convert_revision_regexp)) {
			$row['description'] = preg_replace(array_keys($convert_revision_regexp), $convert_revision_regexp, $row['description']);
		}

	        // There is a strange issue with summaries containing percent signs...
		$date = date ('g.ia, l, jS F Y', substr ($row['time'], 0, -6));
		$issueData = array(
			'title' => utf8_encode($row['summary']),
			'body' => empty($row['description']) ? 'None' : "**[Submitted to the original trac issue database at {$date}]**\n\n" . translate_markup(utf8_encode($row['description'])),
			'assignee' => $assignee,
			'labels' => $ticketLabels
		);
		// set milestone only if ticket is assigned to it
		if (!empty($row['milestone'])) {
			$issueData['milestone'] = $milestones[crc32($row['milestone'])];
		}

		$resp = github_add_issue($issueData);

		if (isset($resp['number'])) {
			// OK
			$tickets[$row['id']] = (int) $resp['number'];
			echo "Ticket #{$row['id']} converted to issue #{$resp['number']}\n";
//
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert a ticket #{$row['id']}: $error\n";
			echo "Terminating conversion; please address the problem and retry\n";
			die;
		}
	}
	// Serialize to restore in future
	file_put_contents($save_tickets, serialize($tickets));
}


if (!$skip_comments) {
	// Export all comments
	$limit = $comments_limit > 0 ? "LIMIT $comments_offset, $comments_limit" : '';
	$res = $trac_db->query("SELECT * FROM {$Q}ticket_change{$Q} where {$Q}field{$Q} = 'comment' AND {$Q}newvalue{$Q} != '' ORDER BY {$Q}ticket{$Q}, {$Q}time{$Q} $limit");
	$rows = set_db_iterator($res);
	foreach ($rows as $row) {
		$text = $row['newvalue'];
		
		// Prepend the date, since the Github API doesn't permit date-setting
		$date = date ('g.ia, l, jS F Y', substr ($row['time'], 0, -6));
		$text = "**[Added to the original trac issue at {$date}]**\n\n" . $text;
		
		// replace svn revision with git revision
		if (!empty($convert_revision_regexp)) {
			$text = preg_replace(array_keys($convert_revision_regexp), $convert_revision_regexp, $text);
		}

		// set github username and password to post comment to ticket
		if (isset($users_list[$row['author']])) {
			$username = $users_list[$row['author']];
			$oauth_token = (isSet ($github_users_oauth_tokens[$username]) ? $github_users_oauth_tokens[$username] : false);
			$password = (isSet ($github_users_passwords[$username]) ? $github_users_passwords[$username] : false);
		} else {
			$username = $default_username;
			$oauth_token = $default_oauth_token;
			$password = $default_password;
			if ( (isset($users_list[$row['author']]) && strtolower($users_list[$row['author']]) != strtolower($username)) || (!isset($users_list[$row['author']]) && $username != $row['author']) ) {
				$text = '**Author: ' . $row['author'] . "**\n" . $text;
			}
		}

		$resp = github_add_comment($tickets[$row['ticket']], translate_markup(utf8_encode($text)));
		if (isset($resp['url'])) {
			// OK
			echo "Added comment {$resp['url']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to add a comment: $error\n";
			echo "Terminating conversion; please address the problem and retry\n";
			die;
		}
	}
}

// Close issues that are closed
if (!$skip_tickets) {
	$username = $default_username;	// #!# Ideally we need to work out who closed the ticket, instead of this
	foreach ($resTicketsAll as $row) {
		if ($row['status'] == 'closed') {
			$issueData = array ('state' => 'closed');
			$resp = github_update_issue($tickets[$row['id']], $issueData);
			if (isset($resp['number'])) {
				echo "Closed issue #{$resp['number']}\n";
			}
		}
	}
}


echo "Done whatever possible, sorry if not.\n";

function set_db_iterator($res) {
        global $database_type;

	if ( $database_type == POSTGRES ) {
		$rows = $res;
	} else {
		$rows = $res->fetchAll();
	}
	return $rows;
}

function github_post($url, $json, $patch = false) {
	global $username, $password, $oauth_token, $rateLimit;
	if ($rateLimit) {
		usleep (ceil (1000000 * (60 * 60) / $rateLimit));
	}
	$ch = curl_init();
	if ($oauth_token) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, array ("Authorization: token {$oauth_token}"));
	} else {	// Fall back to password auth
		curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
	}
	curl_setopt($ch, CURLOPT_USERAGENT, 'trac2github ticket-to-issue conversion script');	// Define user-agent string as required by http://developer.github.com/v3/#user-agent-required
	curl_setopt($ch, CURLOPT_URL, "https://api.github.com$url");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
	if ($patch) {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
	}
	$ret = curl_exec($ch);
	if(!$ret) {
		trigger_error(curl_error($ch));
	}
	curl_close($ch);
	return $ret;
}

function github_add_milestone($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_post("/repos/$project/$repo/milestones", json_encode($data)), true);
}

function github_add_label($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_post("/repos/$project/$repo/labels", json_encode($data)), true);
}

function github_add_issue($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_post("/repos/$project/$repo/issues", json_encode($data)), true);
}

function github_add_comment($issue, $body) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($body);
	return json_decode(github_post("/repos/$project/$repo/issues/$issue/comments", json_encode(array('body' => $body))), true);
}

function github_update_issue($issue, $data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($body);
	return json_decode(github_post("/repos/$project/$repo/issues/$issue", json_encode($data), true), true);
}

function translate_markup($data) {
    // Replace code blocks with an associated language
    $data = preg_replace('/\{\{\{(\s*#!(\w+))?/m', '```$2', $data);
    $data = preg_replace('/\}\}\}/', '```', $data);

    // Replace title blocks
    $data = preg_replace("/^=(\s.+\s)=$/", '#$1#', $data);
    $data = preg_replace("/^==(\s.+\s)==$/", '##$1##', $data);
    $data = preg_replace("/^===(\s.+\s)===$/", '###$1###', $data);
    $data = preg_replace("/^====(\s.+\s)====$/", '####$1####', $data);

	// Do string replacement if required
	global $textReplacements;
	if ($textReplacements) {
		$data = strtr ($data, $textReplacements);
	}

    // Remove e-mail addresses out of courtesy
    global $removeEmailAddresses;
    if ($removeEmailAddresses) {
        $data = preg_replace('|([_\.0-9a-z-]+@([0-9a-z][0-9a-z-]+\.)+[a-z]{2,6})|', '[e-mail address removed]', $data);
    }

    // Replace bold and italic
    $data = preg_replace("/'''(\S.+\S)'''/", '**$1**', $data);
    $data = preg_replace("/''(\S.+\S)''/", '*$1*', $data);

    // Avoid non-ASCII characters, as that will cause trouble with json_encode()
    $data = preg_replace('/[^(\x00-\x7F)]*/','', $data);
	
    // Possibly translate other markup as well?
    return $data;
}

?>
