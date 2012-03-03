<!--
Copyright (c) 2010, Derrick Coetzee (User:Dcoetzee)
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

 * Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

 * Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
--><html>
<head>
<title>Contribution Surveyor</title>
</head>
<body>
<h1>Contribution Surveyor</h1>

<p><i>Contribution Surveyor</i>, created for <a href="http://en.wikipedia.org/wiki/Wikipedia:Contributor_copyright_investigations">Wikipedia:Contributor copyright investigations</a> on the English Wikipedia, is a tool used to analyze the contributions of users with a history of copyright violations. It isolates and ranks contributions that are most likely to be copyright violations.</p>

<p>Please select a user to run a survey on, or enter the name of a user who has already been surveyed to view their report.</p>

<form name="survey1" action="survey.php" method="get">
User: <input type="text" name="user">
<input type="submit" value="Survey user" />
</form>

<!--
<p><b>Existing surveys:</b></p>

<ul>
<?php
/*
$toolserver_mycnf = parse_ini_file("/home/".get_current_user()."/.my.cnf");
$db = mysql_connect('enwiki-p.db.toolserver.org', $toolserver_mycnf['user'], $toolserver_mycnf['password']) or die(mysql_error());
mysql_select_db('enwiki_p', $db) or die(mysql_error());

$diff_info_table = 'u_dcoetzee.cs_diffinfo';

$result = mysql_query('SELECT user_name FROM user, ' . $diff_info_table . ' WHERE diffinfo_user = user_id GROUP BY user_id') or die(mysql_error());
while ($row = mysql_fetch_array($result)) {
  echo '<li>' . '<a href="survey.php?user=' . $row['user_name'] . '">' . $row['user_name'] . '</a>' . '</li>';
}
*/
?>
</ul>
-->
<p>If you have any questions about Contribution Surveyor, please contact its author Derrick Coetzee at <a href="http://en.wikipedia.org/wiki/User_talk:Dcoetzee">his talk page on English Wikipedia</a>.</p>

<p>The PHP source for Contribution Surveyor is available under the <a href="http://www.opensource.org/licenses/bsd-license.php">Simplified BSD License</a>. It must be on Toolserver to run. (<a href="../downloads/contribution_surveyor.tar.gz">.tar.gz</a>) (<a href="../downloads/contribution_surveyor.zip">.zip</a>)</p>

</body>
</html>
