# Savant :: PHP IRC Bot
### Solved a Specific Need
Savant was the name of an earlier PHP IRC Bot that had many functions. Somewhere, that code still exists.

This current iteration was written for a very specific purpose, however. Used in a 600+ user channel on the Freenode IRC network, 
Savant allows channel users the ability to !nominate fellow users (but not themselves!) for Voice (+v). This is used as an incentive, 
to encourage users to engage in helpful, productive chat. 

Once a user is nominated, bot admins (determined by a flag in the database) can view, grant, or deny them. If granted, the user is given voice
immediately, which will expire in 30 days. If an op removes voice from the user, the bot will re-voice them the next time it does its checks
to make sure a.) all users that should be voiced are, and b.) any users that have voice but shouldn't has it removed.

There is currently a manual way to ignore users, should they have a tendency to abuse the bot functions. There is also a manual way to revoke a users' voice privileges early.

The bot logs "seen" data to the user table in MySQL, and currently has a !seen command, though that is planned to eventually be removed.


### Running Savant
You need PHP, MySQL, and that's about it. Currently it runs on a small VPS server; it doesn't require huge amounts of resources.
The bot is started via command line, but it might be a little tricky to start out if you want it to register with NickServ.

***You will also want to manually add yourself into the database, turning the isadmin flag on, so that you can run bot admin commands.***


#### Required Parameters
These parameters are always required. This is important later, because if you want to register with NickServ, you have to restart the bot 
a couple of times with different parameters.

```
-s [irc.server.name]		The IRC server to connect to
-p [port]			The port number to use for the IRC connection
-c [#channel]			The Channel to connect to
-n [nickname]			The Bot's Nickname
-m [127.0.0.1]			The MySQL server to use
-u [mysql user]			The MySQL user to connect as
-q [mysql password]		The MySQL password
-b [database]			The MySQL database to use
```

#### Optional Parameters
These are option parameters, use them if you need them.

```
-d true				Turn on debug mode, vastly increasing the verbosity of console output
-i [nickserv pass]		The bot's NickServ password, use if you want it to identify with NickServ
```

#### First Run, Register with NickServ
The first run of your bot you will likely want it to register with NickServ, and have it join a channel that is not the permanent home (it will output messages you may not want
everyone in the channel to see). To do this, you need to start the bot using this parameter (in addition to required ones above).

```
-e [email address]			The email to use in the NickServ registration
```

Once connected, you can run this command to have the bot register with NickServ.
***Note: You probably want to watch the console output for this part***

```
!nsregister
```

It will register using the value for "-e" and "-i" that you started the bot with. Once done, kill the bot and wait for NickServ to email you the verification code
to the email you provided it. Then, restart the bot, removing the -e parameter, and adding:

```
-v [verification code]		The verification code to send to NickServ after regsitering
```

Then, run this command to have it send the VERIFY to NickServ.

```
!nsverify
```

Now, once done, you can restart the bot a final time, with the normal parameters.


### Bot Admin Commands
Currently, these are commands you would PM to the bot, if you are a bot admin.

```
!nsregister		See above
!nsverify		See above
!noms			Get a list of unhandled nominations
!grant [id]		Grant voice to the nominated user, based on [id] which is shown in the !noms command
!deny [id]		Deny voice to the nominated user, based on [id] which is shown in the !noms command
!whohasvoice		Does a print_r on the $voicedusers array to STDOUT on the console
!updatearrays		Updates the $shouldbevoiced, $voicedusers, and $alluserslist ararys
!printarrays		Does a print_r of both the $shouldbevoiced and $voicedusers arrays
```


### User Commands
These are the commands that users can run.

```
!seen [user]			Gives the last seen information for the [user] requested
!help 				Prints a URL to a gist page, you may want to change this
!nominate [user] [reason]	Adds a nomination for [user] with [reason] as long as [user] isn't themselves or already nominated
```