<?php
// Prevent PHP from stopping the script after 30 sec
// and hide notice messages
set_time_limit(0);
error_reporting(E_ALL & ~E_NOTICE );
date_default_timezone_set("America/Chicago");
system("clear");

echo "Starting bot...\n";

//Bot Settings from command line options
$settings = "s:";	//server to connect to
$settings.= "p:";	//port to use
$settings.= "c:";	//channel to manage
$settings.= "o:";	//operations channel
$settings.= "n:";	//nickname
$settings.= "i:";	//nickserv password
$settings.= "e:";	//email, should only be used if you need to register with NickServ
$settings.= "v:";	//verify code, should only be used to send the verify code to NickServ
$settings.= "m:";	//mysql host to use
$settings.= "u:";	//mysql user
$settings.= "q:";	//mysql password
$settings.= "b:";	//mysql database
$settings.= "d:";	//debug mode
$setting = getopt($settings);
$errmsg = "";
empty($setting['s']) ? $errmsg.= "No server provided!\n" : true ;
empty($setting['p']) ? $errmsg.= "No port provided!\n" : true ;
empty($setting['c']) ? $errmsg.= "No channel provided!\n" : true ;
empty($setting['o']) ? $errmsg.= "No opchannel provided!\n" : true ;
empty($setting['n']) ? $errmsg.= "No nickname provided!\n" : true ;
empty($setting['m']) ? $errmsg.= "No MySQL host provided!\n" : true ;
empty($setting['u']) ? $errmsg.= "No MySQL user provided!\n" : true ;
empty($setting['q']) ? $errmsg.= "No MySQL password provided!\n" : true ;
empty($setting['b']) ? $errmsg.= "No MySQL database provided!\n" : true ;
empty($setting['d']) ? $debugmode = false : $debugmode = true ;
if($errmsg != "") {
	die($errmsg);
}

if($debugmode == true) { echo "Debug mode is enabled.\n"; }

//Connect to MySQL
$mysqlconn = new mysqli($setting['m'],$setting['u'],$setting['q'],$setting['b']);
if($mysqlconn->connect_errno) {
	die("MySQL Connection failed: ". $mysqlconn->connect_error ."\n");
} else {
	echo "MySQL Connection Succeeded.\n";
}

sleep(2);

// Tread lightly.
$socket = fsockopen($setting['s'], $setting['p']);
fputs($socket,"USER ".$setting['n']." ".$setting['n']." ".$setting['n']." ".$setting['n']." :".$setting['n']."\n");
fputs($socket,"NICK ".$setting['n']."\n");
if(isset($setting['i']) && !isset($setting['e'])) {
	sendPRIVMSG("NickServ", "identify ".$setting['i']."");
}

fputs($socket,"JOIN ".$setting['c']."\n");
fputs($socket,"JOIN ".$setting['o']."\n");

//Ignore Message Type, makes for cleaner console output, tuned for Freenode
$ignore = array('001','002','003','004','005','250','251','252','253',
                '254','255','265','266','372','375','376','353','366',
);

while(1) {
    while($data = fgets($socket)) {
		$timestamp = date("Y-m-d H:i:s T");
		$ircdata = processIRCdata($data);
		if(!in_array($ircdata['messagetype'], $ignore)) {
			echo "[$timestamp]  $data";
		}
		
		if($ircdata['command'] == "PING") {
			echo "[$timestamp]  PONG ".$ircdata['messagetype']."";
            fputs($socket, "PONG ".$ircdata['messagetype']."\n");
		}
		
		//Ignore PMs, otherwise process each message to determine if we have an action
		if($ircdata['messagetype'] == "PRIVMSG" && $ircdata['location'] == $setting['n']) {
			sendPRIVMSG($ircdata['usernickname'], "Sorry, I do not accept private messages.");
		} else {
			//For each message, log it to the database for seen stats only for the regular channel
			if($ircdata['location'] == $setting['c']) {	logSeenData($ircdata['usernickname'],$ircdata['userhostname'],$ircdata['fullmessage'],$ircdata['location']); }
							
			// * COMMAND PROCESSING * \\
			$messagearray = $ircdata['messagearray'];
			$firstword = trim($messagearray[1]);
			
			//OpChannel Commands
			if($ircdata['location'] == $setting['o']) {
				switch($firstword) {
					case "!nsregister":
						if(isset($setting['i']) && isset($setting['e'])) {
							sendPRIVMSG("NickServ", "register ".$setting['i']." ".$setting['e']."");
							sendPRIVMSG($ircdata['location'], "Register sent...please restart me without the -e parameter.");
							//die("We just registered with NickServ, need to be restarted with the -e parameter, and include the -v parameter.");
						} else {
							sendPRIVMSG($ircdata['location'], "Proper command-line arguments not parsed.");
						}
						break;
					case "!nsverify":
						if(isset($setting['v'])) {
							sendPRIVMSG("NickServ", "VERIFY REGISTER ".$setting['n']." ".$setting['v']."");
							sendPRIVMSG($ircdata['location'], "Register verify sent. Please restart me without -e or -v.");
							//die("We just registered with NickServ, need to be restarted with the -e  or -v parameters.");
						} else {
							sendPRIVMSG($ircdata['location'], "Proper command-line arguments not parsed.");
						}
						break;
					case "!noms":
						getNominations();
						break;
				}
			//Regular channel commands
			} else {
				switch ($firstword) {
					case ".say":
					case "!say":
						$asdf = "PRIVMSG  ".$ircdata['location']." :".$ircdata['commandargs']."";
						echo "[$timestamp]  $asdf";
						fputs($socket, "PRIVMSG ".$ircdata['location']." :".$ircdata['commandargs']."\n");
						break;
					case ".seen":
					case "!seen":
						sendPRIVMSG($ircdata['location'], getSeenData($ircdata['usernickname'],$ircdata['location'],$ircdata['commandargs']));
						break;
					case ".nominate":
					case "!nominate":
						$nomineepieces = explode(" ",$ircdata['commandargs']);
						$nominee = $nomineepieces[0];
						$nominationreason = NULL; for ($i = 1; $i < count($nomineepieces); $i++) { $nominationreason .= $nomineepieces[$i] . ' '; }
						if($nominee == $ircdata['usernickname']) { 
							sendPRIVMSG($ircdata['location'], "You cannot nominate yourself!"); 
						} else {
							sendPRIVMSG($ircdata['usernickname'], nominateUser($nominee,$ircdata['usernickname'],$nominationreason));
						}
						break;
				  }
			}
			// * END COMMAND PROCESSING * \\
		}
    }
}
function getNominations() {
	global $timestamp;
	global $mysqlconn;
	global $setting;
	
	$sqlstmt = $mysqlconn->prepare('SELECT nominator,nominee,nominationtime,nominationreason FROM nominations WHERE status = "new"');
	$sqlstmt->execute();
	$sqlstmt->store_result();
	$sqlstmt->bind_result($nominator,$nominee,$nominationtime,$nominationreason);
	$sqlrows = $sqlstmt->num_rows;
	if($sqlrows > 0) {
		while($sqlstmt->fetch()) {
			sendPRIVMSG($setting['o'], "[$nominationtime] - $nominator nominates $nominee for voice, reason: $nominationreason");
		}
	} else {
		sendPRIVMSG($setting['o'], "There are no new nominations.");
	}
}
function nominateUser($nominee,$nominator,$nominationreason) {
	global $socket;
	global $timestamp;
	global $debugmode;
	global $mysqlconn;
	global $setting;
	
	$sqlstmt = $mysqlconn->prepare('SELECT hostmask FROM usertable WHERE nick = ?');
	$sqlstmt->bind_param('s', $nominee);
	$sqlstmt->execute();
	$sqlstmt->store_result();
	$sqlstmt->bind_result($hostmask);
	$sqlrows = $sqlstmt->num_rows;
	if($sqlrows == 1) {
		while($sqlstmt->fetch()) {
			$nomineefull = "".$nominee."@".$hostmask."";
			$sqlstmt2 = $mysqlconn->prepare("SELECT * FROM nominations WHERE nominee = ?");
			$sqlstmt2->bind_param('s', $nomineefull);
			$sqlstmt2->execute();
			$sqlstmt2->store_result();
			$sqlrows2 = $sqlstmt2->num_rows;
			if($sqlrows2 == 0) {
				$sqlstmt3 = $mysqlconn->prepare("INSERT INTO nominations(nominator,nominee,nominationtime,nominationreason,status) VALUES(?,?,?,?,'new')");
				$sqlstmt3->bind_param('ssss', $nominator,$nomineefull,$timestamp,$nominationreason);
				$sqlstmt3->execute();
			} else {
				$return = "Thank you for nominating, however, the nominee '$nomineefull' has already been nominated.";
				return $return;
			}
		}
		if($mysqlconn->affected_rows > 0) {
			if($debugmode == true) { echo "[$timestamp]  Added nomination for user $nomineefull by $nominator, reason $nominationreason"; }
			$return = "Thank you for your nomination! It has been added to the queue.";
			sendPRIVMSG($setting['o'], "A new nomination has been queued - '$nominator' nominates '$nomineefull' for voice, reason: ".$nominationreason."");
		} else  {
			if($debugmode == true) { echo "[$timestamp]  Failed to add nomination for user $nomineefull by $nominator, MySQL error ".mysqli_error($mysqlconn).""; }
			$return = "Thank you for participating. Unfortunately, something happened and I was not able to add your nomination to the queue.";
		}
	} else {
		$return = "You can only nominate a user I have seen before. Has '$nominee' spoken here before?";
		return $return;
	}
	return $return;
}
function sendPRIVMSG($location,$message) {
	global $socket;
	fputs($socket, "PRIVMSG ".$location." :".$message."\n");
	return;
}
function logSeenData($nick,$hostmask,$message,$channel) {
	global $mysqlconn;
	global $timestamp;
	global $debugmode;
	$lastmessage = mysql_escape_string($message);
	$sqlstmt = $mysqlconn->prepare("INSERT INTO usertable(nick,hostmask,lastseen,lastseenchannel,lastmessage) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE lastseen=?, lastmessage=?, lastseenchannel=?");
	$sqlstmt->bind_param('ssssssss',$nick,$hostmask,$timestamp,$channel,$lastmessage,$timestamp,$lastmessage,$channel);
	$sqlstmt->execute();
	if($mysqlconn->affected_rows > 0) {
		if($debugmode == true) { echo "[$timestamp]  Updated seen data: $nick@$hostmask lastseen $timestamp message $lastmessage"; }
		return;
	} else {
		if($debugmode == true) { echo "[$timestamp]  Failed to update seen data: $nick@$hostmask lastseen $timestamp message $lastmessage - MySQL error ".mysqli_error($mysqlconn).""; }
		return;
	}
}
function getSeenData($requester,$location,$usertoquery) {
	global $mysqlconn;
	global $timestamp;
	global $debugmode;
	global $setting;
	if($usertoquery == $setting['n']) { $return = "I am right here..."; return $return; }
	if($usertoquery == $requester) { $return = "Having an out of body experience? Need a mirror?"; return $return; }
	if($usertoquery == "") { $return = "You need to specify a user to look up. Try again."; return $return; }
	
	$sqlstmt = $mysqlconn->prepare("SELECT nick,hostmask,lastseen,lastmessage FROM usertable WHERE nick=? AND lastseenchannel=?");
	$sqlstmt->bind_param('ss',$usertoquery,$location);
	$sqlstmt->execute();
	$sqlstmt->store_result();
	$sqlstmt->bind_result($nick,$hostmask,$lastseen,$lastmessage);
	$sqlrows = $sqlstmt->num_rows;
	if($sqlrows > 0) {
		while($sqlstmt->fetch()) {
			$return = "$requester - The user '$usertoquery' was last seen using hostmask '".$hostmask."' on ".$lastseen." saying: '".$lastmessage."'";
		}
	} else {
		$return = "$requester - I was unable to locate seen data for '$usertoquery'.";
	}
	return $return;
}
function processIRCdata($data) {
	global $debugmode;
	$pieces = explode(' ', $data);
	$messagearray = explode(':', $pieces[3]);
	$command = $pieces[0];
	$messagetype = $pieces[1];
	$location = $pieces[2];
	$userpieces1 = explode('@', $pieces[0]);
	$userpieces2 = explode('!', $userpieces1[0]);
	$userpieces3 = explode(':', $userpieces2[0]);
	$userhostname = $userpieces1[1];
	$usernickname = $userpieces3[1];
	$fullmessage = NULL; for ($i = 3; $i < count($pieces); $i++) { $fullmessage .= $pieces[$i] . ' '; }
	$fullmessage = substr($fullmessage, 1);
	$fullmessage = trim($fullmessage);
	$commandargs = NULL; for ($i = 4; $i < count($pieces); $i++) { $commandargs .= $pieces[$i] . ' '; }
	$commandargs = trim($commandargs);
	$return = array(
		'messagearray'	=>	$messagearray,
		'messagetype'	=>	$messagetype,
		'command'       =>  $command,
		'location'      =>  $location,
		'userhostname'	=>	$userhostname,
		'usernickname'	=>	$usernickname,
		'commandargs'	=>	$commandargs,
		'fullmessage'	=>	$fullmessage
	);
	if($debugmode == true) { 
		print_r($return); 
		echo "RAWDATA - $data";
	}
	return $return;
}
?>