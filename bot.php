<?php
// Prevent PHP from stopping the script after 30 sec
// and hide notice messages
set_time_limit(0);
date_default_timezone_set("America/Chicago");

//Server Settings from command line options
$sopts = "s:";
$sopts.= "p:";
$sopts.= "c:";
$sopts.= "n:";
$lopts = array(
               "nspass:",
              );
$options = getopt($sopts,$lopts);

$chan = $options['c'];
$server = $options['s'];
$port = $options['p'];
$nick = $options['n'];
$nspass = $options['nspass'];
$errmsg = "";

if($chan == "") {
  $errmsg.= "No channel provided!\n";
}
if($server == "") {
  $errmsg.= "No server provided!\n";
}
if($port == "") {
  $errmsg.= "No port provided!\n";
}
if($nick == "") {
  $errmsg.= "No nick provided!\n";
}
if($errmsg != "") {
  die($errmsg);
}

// Tread lightly.
$socket = fsockopen("$server", $port);
fputs($socket,"USER $nick $nick $nick $nick :$nick\n");
fputs($socket,"NICK $nick\n");
if($nspass != "") {
  fputs($socket,"PRIVMSG NickServ :identify $nspass\n");
}
fputs($socket,"JOIN ".$chan."\n");

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