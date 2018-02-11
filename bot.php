<?php
// Prevent PHP from stopping the script after 30 sec
// and hide notice messages
set_time_limit(0);
error_reporting(E_ALL & ~E_NOTICE);
date_default_timezone_set("America/Chicago");

//Bot Settings from command line options
$settings = "s:";	//server to connect to
$settings.= "p:";	//port to use
$settings.= "c:";	//channel to manage
$settings.= "o:";	//operations channel
$settings.= "n:";	//nickname
$settings.= "i:";	//nickserv password
$settings.= "d";	//debug mode
$setting = getopt($settings);
$errmsg = "";
empty($setting['c']) ? $errmsg.= "No channel provided!\n" : true ;
empty($setting['s']) ? $errmsg.= "No server provided!\n" : true ;
empty($setting['p']) ? $errmsg.= "No port provided!\n" : true ;
empty($setting['n']) ? $errmsg.= "No nickname provided!\n" : true ;
empty($setting['o']) ? $errmsg.= "No opchannel provided!\n" : true ;
empty($setting['d']) ? $debugmode = true : true ;
if($errmsg != "") {
  die($errmsg);
}

//Connect to MySQL
$mysqlhost = "localhost";
$mysqluser = "savant";
$mysqlpass = "S@v@nTB0t";
$mysqldb = "savant";
$mysqlconn = mysqli_connect($mysqlhost,$mysqluser,$mysqlpass, $mysqldb);
if(!$mysqlconn) {
  die("\tMySQL Connection failed: ". mysqli_connect_errno() . "". mysqli_connect_error() . "\n");
} else {
  echo "\tMySQL Connection Succeeded.\n";
}

// Tread lightly.
$socket = fsockopen($setting['s'], $setting['p']);
fputs($socket,"USER ".$setting['n']." ".$setting['n']." ".$setting['n']." ".$setting['n']." :".$setting['n']."\n");
fputs($socket,"NICK ".$setting['n']."\n");
if($nspass != "") {
  fputs($socket,"PRIVMSG NickServ :identify ".$setting['i']."\n");
}
fputs($socket,"JOIN ".$setting['c']."\n");
fputs($socket,"JOIN ".$setting['o']."\n");

//Ignore Message Type, makes for cleaner console output, tuned for Freenode
$ignore = array('001','002','003','004','005','250','251','252','253',
                '254','255','265','266','372','375','376','353','366',
);

while(1) {
    while($data = fgets($socket)) {
		$timestamp = date("Y-m-d H:i:s");
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
			//For each message, log it to the database for seen stats
			logSeenData($ircdata['usernickname'],$ircdata['userhostname'],$ircdata['fullmessage']);
							
			// * COMMAND PROCESSING * \\
			$messagearray = $ircdata['messagearray'];
			$firstword = trim($messagearray[1]);
			
			//OpChannel Commands
			if($ircdata['location'] == $setting['o']) {
				switch($firstword) {
					case ".join":
					case "!join":
						if($ircdata['commandargs'][0] !== "#") {
							sendPRIVMSG($ircdata['location'], "That doesn't look like a channel name.");
						} else {
							fputs($socket, "JOIN ".$ircdata['commandargs']."\n");
						}
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
				  }
			}
			// * END COMMAND PROCESSING * \\
		}
    }
}
function sendPRIVMSG($location,$message) {
	global $socket;
	fputs($socket, "PRIVMSG ".$location." :".$message."\n");
	return;
}
function logSeenData($nick,$hostmask,$message) {
		global $mysqlconn;
		global $timestamp;
		$seentime = date("Y-m-d H:i:s T");
		$sql = "INSERT INTO usertable(nick,hostmask,lastseen,lastmessage) VALUES('$nick','$hostmask','$seentime','$message') ON DUPLICATE KEY UPDATE lastseen='$seentime', lastmessage='$message'";
		if(mysqli_query($mysqlconn,$sql)) {
			echo "[$timestamp]  Updated seen data: $nick@$hostname lastseen $seentime message $message";
		} else {
			echo "[$timestamp]  Failed to update seen data: $nick@hostname lastseen $seentime message $message";
		}
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
	$fullmessagearray = explode(":", $data);
	$fullmessage = $fullmessagearray[2];
	$commandargs = NULL; for ($i = 4; $i < count($pieces); $i++) { $commandargs .= $pieces[$i] . ' '; }
	$commandargs = trim($commandargs);
	$return = array(
		'messagearray'	=>	$messagearray,
		'messagetype'	=>	$messagetype,
		'command'		=>	$command,
		'location'		=>	$location,
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