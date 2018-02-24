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

//Ignore Message Type, makes for cleaner console output, tuned for Freenode
$ignore = array('001','002','003','004','005','250','251','252','253',
                '254','255','265','266','353','372','375','376','366',
);

$epoch = time();
$nextnamescheck = $epoch + 10;
$voicedusers = array();
$alluserslist = array();
//$shouldhavevoice = createShouldBeVoicedArray();

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
		
		//This is when we see "NAMES", so we can go ahead and update the $voicedusers list
		if($ircdata['messagetype'] == "353") {
			$voicedusers = createVoicedUsersArray();
			createAllUsersList();
			$arraycount = count($alluserslist);
			echo "[$timestamp]  Built alluserslist with $arraycount names\n";
		}
		
		//This is where we refresh the arrays with new data, check that nobody is voiced that shouldn't be,
		//voicing users that should have it, and adding 60 seconds to the timer
		$nowepoch = time();
		if($nowepoch > $nextnamescheck) {
			echo "[$timestamp]  Current epoch time $nowepoch is later than $nextnamescheck, updating shouldbevoiced list.\n";
			$shouldhavevoice = createShouldBeVoicedArray();
			
			//check all users and if they're supposed to be voiced, voice them
			foreach($alluserslist as $usertocheck) {
				if(shouldBeVoiced($usertocheck) == true && isUserVoiced($usertocheck) == false) {
					echo "[$timestamp]  User ".$usertocheck." should be voiced and isn't, I will grant it.\n";
					plusV($usertocheck);
				}
			}
			
			//check all the voiced users in case their granted time has expired
			foreach($voicedusers as $usertocheck) {
				checkUserVoiceExpired($usertocheck);
			}
			
			//check all users with voice, remove those that dont have it, grant if they should but dont
			echo "[$timestamp]  Checking that all voices set properly.\n";
			foreach($voicedusers as $usertocheck) {
				if(!in_array($usertocheck,$shouldhavevoice)) {
					echo "[$timestamp]  User ".$usertocheck." shouldn't be voiced and is, I will remove it.\n";
					minusV($usertocheck);
				} elseif(isUserVoiced($usertocheck) == false) {
					echo "[$timestamp]  User ".$usertocheck." should be voiced and isn't, I will grant it.\n";
					plusV($usertocheck);
				} else {
					true;
				}
			}

			//Send a NAMES so the voicedusers array gets updated after we may have just +/-v'd people
			echo "[$timestamp]  Sending NAMES command to update voicedusers list.\n";
			fputs($socket, "NAMES ".$setting['c']."\n");
			$nextnamescheck = $nowepoch + 300;
		}

		//For each message, log it to the database for seen stats only for the regular channel
		if($ircdata['location'] == $setting['c']) {	
			logSeenData($ircdata['usernickname'],$ircdata['userhostname'],$ircdata['fullmessage'],$ircdata['location']); 
		}
		
		//
		
		//Accept PMs from admins, otherwise ignore; then continue processing messages to determine if we have an action
		if($ircdata['messagetype'] == "PRIVMSG" && $ircdata['location'] == $setting['n']) {
			$messagearray = $ircdata['messagearray'];
			$firstword = trim($messagearray[1]);
			if(isUserAdmin($ircdata['usernickname']) == true) {
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
					case "!grant":
						voiceAction("grant",$ircdata['commandargs']);
						break;
					case "!whohasvoice":
						print_r($voicedusers);
						break;
					case "!updatearrays":
						$shouldhavevoice = createShouldBeVoicedArray();
						fputs($socket, "NAMES ".$setting['c']."\n");
						break;
					case "!printarrays":
						echo "shouldhavevoice\n";
						print_r($shouldhavevoice);
						echo "voicedusers\n";
						print_r($voicedusers);
						echo "allusers\n";
						print_r($alluserslist);
						break;
				}						
			} else {
				sendPRIVMSG($ircdata['usernickname'], "Sorry, I do not accept private messages.");
			}
		} elseif($ircdata['messagetype'] == "PRIVMSG" && $ircdata['location'] == $setting['c']) {
			$messagearray = $ircdata['messagearray'];
			$firstword = trim($messagearray[1]);
			if($firstword[0] == "!") {
				echo "[$timestamp]  Seen command $firstword in channel ".$ircdata['location']."\n";
			}
			if(isUserIgnored($ircdata['usernickname']) == false) {
				switch($firstword) {
					case "!seen":
						sendPRIVMSG($ircdata['location'], getSeenData($ircdata['usernickname'],$ircdata['location'],$ircdata['commandargs']));
						break;
					case "!help":
						sendPRIVMSG($ircdata['location'], "https://gist.github.com/mistiry/e660e2ac2ee434dac830bfbeedd5ddbd");
						break;
					case "!nominate":
						$nomineepieces = explode(" ",$ircdata['commandargs']);
						$nominee = $nomineepieces[0];
						if(!in_array($nominee,$alluserslist)) {
							sendPRIVMSG($ircdata['location'], "I don't see that user in the channel. Please try again when the user is present.");
						} else {
							$nominationreason = NULL; for ($i = 1; $i < count($nomineepieces); $i++) { $nominationreason .= $nomineepieces[$i] . ' '; }
							if($nominee == $ircdata['usernickname']) { 
								sendPRIVMSG($ircdata['location'], "You cannot nominate yourself!"); 
							} else {
								sendPRIVMSG($ircdata['usernickname'], nominateUser($nominee,$ircdata['usernickname'],$nominationreason));
							}
						}
						break;
				}
			}
		}
		// * END COMMAND PROCESSING * \\
	}
}
function createVoicedUsersArray() {
	global $timestamp;
	global $setting;
	global $ircdata;
	
	$voicedusers = array();
	$pieces = explode(" ", $ircdata['fullmessage']);
	
	foreach($pieces as $names) {
		if($names[0] == "+") {
			$namenoflags = substr($names,1);
			array_push($voicedusers,$namenoflags);
		}
	}
	return $voicedusers;
}
function createAllUsersList() {
	global $timestamp;
	global $setting;
	global $ircdata;
	global $alluserslist;
	
	$pieces = explode(" ", $ircdata['fullmessage']);
	
	foreach($pieces as $names) {
		if($names[0] == ":") {
			$names = substr($names,1);
		}
		if( ($names[0] == "+") || ($names[0] == "@") ) {
			$name = substr($names,1);
		} else {
			$name = $names;
		}
		if(!in_array($name,$alluserslist)) {
			array_push($alluserslist,$name);
		}
	}
	return true;
}
function createShouldBeVoicedArray() {
	global $timestamp;
	global $mysqlconn;
	global $setting;
	global $debugmode;
	global $shouldhavevoice;
	$shouldhavevoice = array();
	echo "[$timestamp]  Updating ShouldBeVoiced array from database.\n";
	$sqlstmt = $mysqlconn->prepare('SELECT nick,shouldhavevoice FROM usertable');
	$sqlstmt->execute();
	$sqlstmt->store_result();
	$sqlstmt->bind_result($resultnick,$hasvoice);
	$sqlrows = $sqlstmt->num_rows;
	if($sqlrows > 0) {
		while($sqlstmt->fetch()) {
			if($hasvoice == 1) {
				array_push($shouldhavevoice,$resultnick);
			}
		}
	} else {
		echo "[$timestamp]  No rows returned during check for who should have voice.\n";
	}
	($debugmode == true) ? print_r($shouldhavevoice) : true ;
	return $shouldhavevoice;
}
function isUserVoiced($nick) {
	global $voicedusers;
	if(in_array($nick,$voicedusers)) {
		return true;
	} else {
		return false;
	}
}
function shouldBeVoiced($nick) {
	global $shouldhavevoice;
	if(in_array($nick,$shouldhavevoice)) {
		return true;
	} else {
		return false;
	}
}
function isUserAdmin($nick) {
	global $mysqlconn;
	global $timestamp;
	
	$sqlstmt = $mysqlconn->prepare('SELECT isadmin FROM usertable WHERE nick=?');
	$sqlstmt->bind_param('s', $nick);
	$sqlstmt->execute();
	$sqlstmt->store_result();
	$sqlstmt->bind_result($isadmin);
	$sqlrows = $sqlstmt->num_rows;
	if($sqlrows > 0) {
		while($sqlstmt->fetch()) {
			if($isadmin == "1") {
				echo "[$timestamp]  Granted user $nick admin rights as database flag isadmin = 1\n";
				return true;
				
			} else {
				echo "[$timestamp]  Denied user $nick admin rights as database flag isadmin = '$isadmin'\n";
				return false;
			}
		}
	}
}
function isUserIgnored($nick) {
	global $mysqlconn;
	global $ircdata;
	global $timestamp;
	
	$sqlstmt = $mysqlconn->prepare('SELECT isignored FROM usertable WHERE nick=?');
	$sqlstmt->bind_param('s', $nick);
	$sqlstmt->execute();
	$sqlstmt->store_result();
	$sqlstmt->bind_result($isignored);
	$sqlrows = $sqlstmt->num_rows;
	if($sqlrows > 0) {
		while($sqlstmt->fetch()) {
			if($isignored == "1") {
				//echo "[$timestamp]  User '".$ircdata['location']."' ignored, database value isignored = '$isignored'\n";
				return true;
			} else {
				//echo "[$timestamp]  User '".$ircdata['location']."' command allowed, database value isignored = '$isignored'\n";
				return false;
			}
		}
	}
}
function voiceAction($type,$id) {
	global $timestamp;
	global $mysqlconn;
	global $setting;
	global $debugmode;
	global $socket;
	
	if($debugmode == true) { echo "[$timestamp]  Received a request to perform voice action $type for id $id\n"; }
	
	if($type == "grant") {
		$now = time();
		$newexpiredate = $now + 2592000; //Add 30 days in seconds to current epoch time
		
		$sqlstmt = $mysqlconn->prepare('SELECT nominee FROM nominations WHERE id=?');
		$sqlstmt->bind_param('i',$id);
		$sqlstmt->execute();
		$sqlstmt->store_result();
		$sqlstmt->bind_result($nominee);
		$sqlrows = $sqlstmt->num_rows;
		if($sqlrows > 0) {
			while($sqlstmt->fetch()) {
				$pieces = explode("@",$nominee);
				$nick = $pieces[0];
				$hostmask = $pieces[1];				
				$sqlstmt2 = $mysqlconn->prepare('UPDATE usertable SET shouldhavevoice=1, voiceexpiredate=? WHERE nick=? AND hostmask=?');
				$sqlstmt2->bind_param('sss',$newexpiredate,$nick,$hostmask);
				$sqlstmt2->execute();
				if($mysqlconn->affected_rows > 0) {
					sendPRIVMSG($setting['c'], "Granted 30-day voice to user with nomination id of $id.");
					$sqlstmt3 = $mysqlconn->prepare('UPDATE nominations SET status="granted" WHERE id=?');
					$sqlstmt3->bind_param('i',$id);
					$sqlstmt3->execute();
					if($mysqlconn->affected_rows > 0) {
						//sendPRIVMSG($setting['o'], "Successfully marked nomination as granted.");
						plusV($nick);
					} else {
						sendPRIVMSG($setting['c'], "Something Happened - unable to mark the nomination as granted.");
					}
				} else {
					sendPRIVMSG($setting['c'], "Something Happened - unable to grant voice.");
				}
			}
		}
	}
	if($type == "revoke") {
		$sqlstmt = $mysqlconn->prepare('UPDATE usertable SET shouldhavevoice=NULL, voiceexpiredate=NULL WHERE nick=?');
		$sqlstmt->bind_param('s',$id);
		$sqlstmt->execute();
		if($mysqlconn->affected_rows > 0) {
			sendPRIVMSG($setting['c'], "Revoked voice from user $id after time expired.");
			minusV($id);
		} else {
			true;
			//sendPRIVMSG($setting['o'], "Something Happened - unable to revoke voice for user $id.");
		}
	}
	fputs($socket, "NAMES ".$setting['c']."\n");
	return;
}
function plusV($nick) {
	global $timestamp;
	global $setting;
	global $socket;
	fputs($socket, "MODE ".$setting['c']." +v $nick\n");
}
function minusV($nick) {
	global $timestamp;
	global $setting;
	global $socket;
	fputs($socket, "MODE ".$setting['c']." -v $nick\n");
}
function checkUserVoiceExpired($nick) {
	global $timestamp;
	global $mysqlconn;
	global $voicedusers;
	$time = time();
	
	$sqlstmt = $mysqlconn->prepare('SELECT shouldhavevoice,voiceexpiredate FROM usertable WHERE nick = ?');
	$sqlstmt->bind_param('s', $nick);
	$sqlstmt->execute();
	$sqlstmt->store_result();
	$sqlstmt->bind_result($shouldhavevoice,$voiceexpiredate);
	$sqlrows = $sqlstmt->num_rows;
	if($sqlrows == 1) {
		while($sqlstmt->fetch()) {
			if( ($time > $voiceexpiredate) && ($shouldhavevoice == 1) )  {
				voiceAction("revoke",$nick);
				echo "[$timestamp]  User $nick grant expired, revoking voice now.\n";
			} else {
				echo "[$timestamp]  User $nick grant not yet expired or nonexistent.\n";
			}
		}
		return true;
	}
	return false;
}
function getNominations() {
	global $timestamp;
	global $mysqlconn;
	global $ircdata;

	
	$sqlstmt = $mysqlconn->prepare('SELECT id,nominator,nominee,nominationtime,nominationreason FROM nominations WHERE status = "new"');
	$sqlstmt->execute();
	$sqlstmt->store_result();
	$sqlstmt->bind_result($id,$nominator,$nominee,$nominationtime,$nominationreason);
	$sqlrows = $sqlstmt->num_rows;
	if($sqlrows > 0) {
		while($sqlstmt->fetch()) {
			sendPRIVMSG($ircdata['usernickname'], "$id - $nominator nominates $nominee for voice, reason: $nominationreason ($nominationtime)");
		}
	} else {
		sendPRIVMSG($ircdata['usernickname'], "There are no new nominations.");
	}
	return;
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
			if($debugmode == true) { echo "[$timestamp]  Added nomination for user $nomineefull by $nominator, reason $nominationreason\n"; }
			$return = "Thank you for your nomination! It has been added to the queue.";
			//sendPRIVMSG($setting['o'], "A new nomination has been queued - '$nominator' nominates '$nomineefull' for voice, reason: ".$nominationreason."");
		} else  {
			if($debugmode == true) { echo "[$timestamp]  Failed to add nomination for user $nomineefull by $nominator, MySQL error ".mysqli_error($mysqlconn)."\n"; }
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
		if($debugmode == true) { echo "[$timestamp]  Updated seen data: $nick@$hostmask lastseen $timestamp message $lastmessage\n"; }
		return;
	} else {
		if($debugmode == true) { echo "[$timestamp]  Failed to update seen data: $nick@$hostmask lastseen $timestamp message $lastmessage - MySQL error ".mysqli_error($mysqlconn)."\n"; }
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
		//echo "RAWDATA - $data\n";
	}
	return $return;
}
?>