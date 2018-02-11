<?php
// Prevent PHP from stopping the script after 30 sec
// and hide notice messages
set_time_limit(0);
error_reporting(E_ALL & ~E_NOTICE);
date_default_timezone_set("America/Chicago");

//Server Settings from command line options
$settings = "s:";	//server to connect to
$settings.= "p:";	//port to use
$settings.= "c:";	//channel to manage
$settings.= "o:";	//operations channel
$settings.= "n:";	//nickname
$settings.= "i:";	//nickserv password
$setting = getopt($settings);
$errmsg = "";
empty($setting['c']) ? $errmsg.= "No channel provided!\n" : true ;
empty($setting['s']) ? $errmsg.= "No server provided!\n" : true ;
empty($setting['p']) ? $errmsg.= "No port provided!\n" : true ;
empty($setting['n']) ? $errmsg.= "No nickname provided!\n" : true ;
empty($setting['o']) ? $errmsg.= "No opchannel provided!\n" : true ;
if($errmsg != "") {
  die($errmsg);
}

// Tread lightly.
$socket = fsockopen($setting['s'], $setting['p']);
fputs($socket,"USER $setting['n'] $setting['n'] $setting['n'] $setting['n'] :$setting['n']\n");
fputs($socket,"NICK $setting['n']\n");
if($nspass != "") {
  fputs($socket,"PRIVMSG NickServ :identify $nspass\n");
}
fputs($socket,"JOIN ".$setting['c']."\n");
fputs($socket,"JOIN ".$setting['o']."\n");

//Ignore Message Type, makes for cleaner console output, tuned for Freenode
$ignore = array('001','002','003','004','005','250','251','252','253',
                '254','255','265','266','372','375','376','353','366',
);

while(1) {
    while($data = fgets($socket)) {
        $ex = explode(' ', $data);
        if(!in_array($ex[1],$ignore)) {
          $timestamp = date("Y-m-d H:i:s");
          echo "[$timestamp]  $data";
        }
        $rawcmd = explode(':', $ex[3]);
        $channel = $ex[2];
        $nicka = explode('@', $ex[0]);
        $nickb = explode('!', $nicka[0]);
        $nickc = explode(':', $nickb[0]);
        $host = $nicka[1];
        $nick = $nickc[1];
        if($ex[0] == "PING"){
            echo "[$timestamp]  PONG $ex[1]";
            fputs($socket, "PONG $ex[1]\n");
        }

        $args = NULL; for ($i = 4; $i < count($ex); $i++) { $args .= $ex[$i] . ' '; }
        
        //Look at messages for !command calls (first word must be the command)
        $firstword = trim($rawcmd[1]);
        switch ($firstword) {
            //Stack cases together to accept multiple commands that do the same thing
			case "!say":
				fputs($socket, "PRIVMSG $channel :$args\n");
				break;
          } 
    }
}
?>