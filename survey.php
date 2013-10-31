<?php /* Copyright (c) 2010, Derrick Coetzee (User:Dcoetzee)
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
*/

/*** Options ***/

/* The number of revisions to scan back in the history to see if
   the current edit is a revert. Must be at least 3. Larger values
   may result in more false positives. */
$num_revisions = 6;

/* The maximum number of times an HTTP request will be made while processing
   each edit. Larger numbers detect reverts better while smaller numbers run
   faster. Must be at least 3. Usually only a factor for pre-April 2007
   edits, before rev_len was available. */
$max_fetches_per_edit = 3;

/* Name of the table caching the survey results. You should set it to a table
   you have access to. */
$diff_info_table = 'u_dcoetzee.cs_diffinfo';

/* Every $seconds_per_refresh seconds, if the script is still scanning
   contributions it will stop, show progress, and ask the client to
   auto-refresh. */
$seconds_per_refresh = 30;

/* Number of characters of wikitext that must change for an edit to be
   counted as "major" instead of "minor". For the purposes of copyvio,
   most excerpts under 150 characters would be fair use. */
$default_major_edit_char_count = 150;

/***/

/* Get current time as a floating-point number of seconds since some reference
   point. */
function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

/* Performs the specified query, or else kills the script with an error.
   Allows insertion of debug prints of SQL queries for diagnosis. */
function query($sql)
{
    /* print '<p><small>' . htmlspecialchars($sql) . '</small></p>'; */
    $query_result = mysql_query($sql);
    if (!$query_result) {
        die(mysql_error());
    }
    return $query_result;
}

/* Build a URL for invoking the current script with the given set of
   parameters. */
function get_params($user, $hide_reverts, $hide_minor_edits, $articles_per_section, $major_edit_char_count, $offset, $limit, $start_timestamp, $end_timestamp)
{
  return 'user=' . urlencode($user) . '&' .
	 'hide_reverts=' . urlencode($hide_reverts) . '&' .
	 'hide_minor_edits=' . urlencode($hide_minor_edits) . '&' .
	 'articles_per_section=' . urlencode($articles_per_section) . '&' .
	 'major_edit_char_count=' . urlencode($major_edit_char_count) . '&' .
	 'offset=' . urlencode($offset) . '&' .
	 'limit=' . urlencode($limit) . '&' . 
	 'start_timestamp=' . urlencode($start_timestamp) . '&' .
	 'end_timestamp=' . urlencode($end_timestamp);
}

/* Start an HTTP HEAD request to determine the length of the page at a given
   URL, returning an object that can be passed into finish_get_page_length()
   later to get the result asynchronously. */
function begin_get_page_length($host, $url) {
  $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  if (!isset($cached_ip[$host])) {
    $cached_ip[$host] = gethostbyname($host);
  }
  socket_connect($socket, $cached_ip[$host], 80);
  $request = 'HEAD ' . $url . ' HTTP/1.1' . "\n" .
	     'Host: ' . $host . "\n" .
             'User-Agent: Contribution Surveyor (http://toolserver.org/~dcoetzee/contributionsurveyor/)' . "\n" .
             'Connection: close' . "\n\n";
  socket_send($socket, $request, strlen($request), 0);
  return $socket;
}

/* Takes a result returned by begin_get_page_length() and
   yields the final result, the length of the desired page. */
function finish_get_page_length($socket) {
  socket_recv($socket, $buffer, 2048, MSG_WAITALL);
  if (preg_match('/Content-Length: ([0-9]+)/', $buffer, $matches)) {
    $result = $matches[1];
  } else {
    $result = null;
  }
  socket_close($socket);
  return $result;
}

/* Convert a string in some date/time format to the timestamp
   format used by Mediawiki. Besides accepting raw timestamps,
   it accepts prefixes of raw timestamps corresponding to years,
   years and months, year-month-days, year-month-day-hour-minutes,
   and anything parsable by PHP's strtotime. */
function string_to_timestamp($str)
{
  if (preg_match('/^[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]$/', $str)) {
    return $str;
  }
  if (preg_match('/^[0-9][0-9][0-9][0-9]$/', $str)) {
    return $str . '0101000000';
  }
  if (preg_match('/^[0-9][0-9][0-9][0-9][0-9][0-9]$/', $str)) {
    return $str . '01000000';
  }
  if (preg_match('/^[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]$/', $str)) {
    return $str . '000000';
  }
  if (preg_match('/^[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]$/', $str)) {
    return $str . '00';
  }

  $parsed_time = strtotime($str);
  if ($parsed_time) {
    return date("YmdHis", $parsed_time);
  }
  return null;
}

/* Convert a Mediawiki timestamp to a more human-readable string
   representation. */
function timestamp_to_string($timestamp)
{
  return preg_replace('/([0-9][0-9][0-9][0-9])([0-9][0-9])([0-9][0-9])([0-9][0-9])([0-9][0-9])([0-9][0-9])/', '\1-\2-\3 \4:\5:\6 UTC', $timestamp);
}

/* Called when contribution scanning does not complete before the
   query times out. Prints a message describing current progress. */
function report_progress($diff_info_table, $user_id, $user_name, $raw_timestamp, $revs_retrieved, $time_delta, $raw_count)
{
    $query = 'SELECT COUNT(*) AS revisions_scanned FROM ' . $diff_info_table . ' WHERE diffinfo_user=' . $user_id .
             ($user_id == 0 ? ("AND diffinfo_user_text = '" . mysql_real_escape_string($user_name) . "' ") : '');
    $result2 = query($query);
    $row2 = mysql_fetch_array($result2);
    $revisions_scanned = $row2['revisions_scanned'];

    $query = "SELECT COUNT(*) AS total_revisions from page, revision " .
             "WHERE rev_user=" . $user_id .
             ($user_id == 0 ? ("AND rev_user_text = '" . mysql_real_escape_string($user_name) . "' ") : '') .
             " AND page_namespace=0 AND page_id=rev_page";
    $result2 = query($query);
    $row2 = mysql_fetch_array($result2);
    $total_revisions = $row2['total_revisions'];

    $timestamp = timestamp_to_string($raw_timestamp);
    echo '<html><head><meta http-equiv="refresh" content="0" /></head><body>';
    printf("<p>" . $revisions_scanned . " of " . $total_revisions . " (%0.1f%%) contributions scanned for user " . htmlspecialchars($user_name) . " (up to timestamp " . $timestamp . ")." . ($raw_timestamp < '20070420000000' ? ' The scan will proceed slowly until 2007-04-20 is reached.' : '') . "</p>", 100.0*$revisions_scanned/$total_revisions);
    printf("<p>" . $revs_retrieved . ' contributions scanned in %0.1f sec (%0.1f contributions/sec)' . ".\r\n", $time_delta, $revs_retrieved/$time_delta);
    echo "Got " . $raw_count . " raw revisions.</p>";
    echo '<p>If you stop loading or leave this page the scan will pause and you may return to it later. You may scan multiple users at the same time but multiple clients should not scan the same user at once.</p>';
    echo '</body></html>';

    exit(0);
}

# Set PHP options - user abort is useful for "pausing" when it works.
ignore_user_abort(false);

# Get starting time to measure time elapsed later.
$time_start = microtime_float();

# Connect to database
$toolserver_mycnf = parse_ini_file("/home/".get_current_user()."/.my.cnf");
$db = mysql_connect('enwiki-p.userdb.toolserver.org', $toolserver_mycnf['user'], $toolserver_mycnf['password']) or die(mysql_error());
mysql_select_db('enwiki_p', $db) or die(mysql_error());

# Get and sanitize user name
$user_name = ucfirst(trim(html_entity_decode($_GET['user'], ENT_QUOTES, 'UTF-8')));
$user_name_url = urlencode(str_replace(' ', '_', $user_name));

# Get user ID
if (preg_match('/^[0-9][0-9]?[0-9]?\.[0-9][0-9]?[0-9]?\.[0-9][0-9]?[0-9]?\.[0-9][0-9]?[0-9]?$/', $user_name, $matches)) {
  $user_id = 0;
} else {
  $result = query("SELECT user_id FROM user WHERE user_name = '" . mysql_real_escape_string($user_name) . "'");
  if (!($row = mysql_fetch_array($result))) {
    $user_name = utf8_encode($user_name);
    $result = query("SELECT user_id FROM user WHERE user_name = '" . mysql_real_escape_string($user_name) . "'");
    if (!($row = mysql_fetch_array($result))) {
      die ('User \'' . htmlspecialchars($user_name) . '\' not found.');
    }
  }
  $user_id = $row['user_id'];
}

# Initialize sizes and revids arrays. We init sizes to zero so that correct
# change delta values are produced for page creation.
$sizes = array();
$revids = array();
for ($i=0; $i < $num_revisions; $i++) {
  $sizes[] = 0;
  $revids[] = -1;
}

# Create table for caching reports, if necessary
query("CREATE TABLE IF NOT EXISTS " . $diff_info_table . "(" .
      "   diffinfo_rev int unsigned NOT NULL PRIMARY KEY," .
      "   diffinfo_user int unsigned NOT NULL, INDEX(diffinfo_user)," .
      "   diffinfo_user_text varchar(255) binary NOT NULL," .
      "   diffinfo_page int unsigned NOT NULL, INDEX(diffinfo_page)," .
      "   diffinfo_change int NOT NULL," .
      "   diffinfo_prev_rev int unsigned," .
      "   diffinfo_is_revert tinyint NOT NULL," .
      "   diffinfo_timestamp binary(14) NOT NULL" .
      ")");

# We scan in order of rev_id. Find the place where we left off last time.
$query = "SELECT MAX(diffinfo_rev) AS starting_rev FROM " . $diff_info_table . " " .
         "WHERE diffinfo_user = " . $user_id . " " .
         ($user_id == 0 ? ("AND diffinfo_user_text = '" . mysql_real_escape_string($user_name) . "' ") : '');
$result = query($query);
while ($row = mysql_fetch_array($result)) {
    $starting_rev = $row['starting_rev'];
}
if (is_null($starting_rev)) {
    $starting_rev = 0;
}

/* Begin scanning contributions by the user. We need to compute
   for every contribution whether it's a revert and the change
   in wikitext size between this revision and the previous one.
   We also grab and cache the timestamp (denormalized) for more
   efficient queries later, but not the title. */

$revs_retrieved = 0;
$query = "SELECT page_id, rev_id, rev_timestamp from page, revision " .
         "WHERE rev_user=" . $user_id . " " .
         ($user_id == 0 ? ("AND rev_user_text = '" . mysql_real_escape_string($user_name) . "' ") : '') .
         "AND page_namespace=0 " .
         "AND page_id=rev_page " .
         "AND rev_id > " . $starting_rev;
$result = query($query);
while (1) {
  $size_new = -1;
  $size_old = -1;
  $revid_new = -1;
  $revid_old = -1;

  # The query_ahead/prefetch scheme lets us overlay HTTP HEAD fetches
  # with database queries. $query_ahead_* are arrays containing
  # prefetched data, and are used whenever they're nonempty.

  if (count($query_ahead_row) == 0) {
    $row = mysql_fetch_array($result);
    if (!$row) break;

    $query = "SELECT rev_id, rev_len, rev_text_id " .
             "FROM revision WHERE rev_page=" . $row['page_id'] . " " .
             "AND rev_id <= " . $row['rev_id'] . " " .
             "ORDER BY rev_id DESC LIMIT " . $num_revisions;
    $result2 = query($query);

    for ($i=0; $i < $num_revisions; $i++) {
      $sizes[$i] = 0;
      $revids[$i] = -1;
    }

    $i = 0;
    while ($row2 = mysql_fetch_array($result2)) {
      $revids[$i] = $row2['rev_id'];
      $sizes[$i] = $row2['rev_len'];
      $i++;
    }
  } else {
    $row = array_shift($query_ahead_row);
    $revids = array_shift($query_ahead_revids);
    $sizes = array_shift($query_ahead_sizes);
  }

  /* Use cached sizes wherever possible - this is useful when
     a user makes several adjacent edits, since they'll show
     up in several "result2" queries in the inner loop. */
  for ($i = 0; $i < $num_revisions; $i++)
  {
    if (is_null($sizes[$i]) && isset($cached_sizes[$revids[$i]])) {
      $sizes[$i] = $cached_sizes[$revids[$i]];
    }
  }

  /* Look for any remaining null size revisions and do HTTP HEAD queries from
     the live wiki using action=raw to get their sizes. We retrieve up to
     $max_fetches_per_edit simultaneously, and also cache database queries
     while it's retrieving. */

  /* Look for null size revisions and begin HTTP requests */
  $fetch_count = 0;
  for ($i = 0; $i < $num_revisions; $i++) {
    unset($get_page_length_objs[$i]);
    if (is_null($sizes[$i]) && $fetch_count < $max_fetches_per_edit) {
      $fetch_count++;
      /* If rev_len is NULL we must compute it
	 from the actual page text. Use raw interface
	 to get at it. */
      $raw_count++;
      $get_page_length_objs[$i] = begin_get_page_length('en.wikipedia.org', "/w/index.php?oldid=" . $revids[$i] . "&action=raw");
    }
  }

  if (count($get_page_length_objs) > 1) {
    /* Fetch some things while waiting for our downloads */
    for ($prefetch_count=0; $prefetch_count < 1; $prefetch_count++) {
      $row_prefetch = mysql_fetch_array($result);
      if (!$row_prefetch) { break; }

      $query = "SELECT rev_id, rev_len, rev_text_id" . " " .
               "FROM revision WHERE rev_page=" . $row_prefetch['page_id'] . " " .
               "AND rev_id <= " . $row_prefetch['rev_id'] . " " .
               "ORDER BY rev_id DESC LIMIT " . $num_revisions;
      $result2_prefetch = query($query);

      for ($i=0; $i < $num_revisions; $i++) {
	$sizes_prefetch[$i] = 0;
	$revids_prefetch[$i] = -1;
      }

      $i = 0;
      while ($row2_prefetch = mysql_fetch_array($result2_prefetch)) {
	$revids_prefetch[$i] = $row2_prefetch['rev_id'];
	$sizes_prefetch[$i] = $row2_prefetch['rev_len'];
	$i++;
      }

      $query_ahead_row[] = $row_prefetch;
      $query_ahead_revids[] = $revids_prefetch;
      $query_ahead_sizes[] = $sizes_prefetch;
    }
  }

  /* Complete downloads and store (and cache) sizes. */
  for ($i = 0; $i < $num_revisions; $i++) {
    if (isset($get_page_length_objs[$i])) {
      $sizes[$i] = finish_get_page_length($get_page_length_objs[$i]);
      $cached_sizes[$revids[$i]] = $sizes[$i];
    }
  }

  $size_new = $sizes[0];
  $revid_new = $revids[0];
  $size_old = $sizes[1];
  $revid_old = $revids[1];

  # Search for a revert by looking for a revision the same
  # size as the current one within $num_revisions revisions.
  # May trigger false positives occasionally.
  $is_revert = 0;
  if ($size_new != $size_old)
  {
    for ($i = 2; $i < $num_revisions; $i++)
      {
	if ($size_new == $sizes[$i])
	  {
	    $is_revert = 1;
	    break;
	  }
      }
  }

  if ($size_old == 0)
  {
    # There is no previous revision - this is a page creation.
    $revid_old = 'NULL';
  }

  $diff_change = $size_new - $size_old;

  # Don't modify the database if the client is gone
  if(connection_status() != CONNECTION_NORMAL) {
    exit(0);
  }

  /* Insert all the data we've gathered/computed about this contribution into
     the survey results database. */
  $query = "INSERT INTO " . $diff_info_table . " (diffinfo_rev, diffinfo_user, diffinfo_user_text, diffinfo_page, diffinfo_change, diffinfo_prev_rev, diffinfo_is_revert, diffinfo_timestamp) " .
	      "VALUES (" . $row['rev_id'] . "," .
              $user_id . "," .
              "'" . mysql_real_escape_string($user_name) . "'," .
              $row['page_id'] . "," .
	      $diff_change . "," .
              $revid_old . "," .
              $is_revert . "," .
              $row['rev_timestamp'] . ")";
  query($query);

  $revs_retrieved++;

  $time_delta = microtime_float() - $time_start;
  if ($time_delta > $seconds_per_refresh) {
    /* Timed out for now - report progress and exit. Will autofresh the page
       if the client is still present. */
    report_progress($diff_info_table, $user_id, $user_name, $row['rev_timestamp'], $revs_retrieved, $time_delta, $raw_count);
    exit(0);
  }
}

# Done scanning and importing all contributions!

# Read and sanitize all other GET parameters

$articles_per_section = 20;
if ($_GET['articles_per_section'] != '' && is_numeric($_GET['articles_per_section']) && $_GET['articles_per_section'] >= 0) {
    $articles_per_section = $_GET['articles_per_section'];
}
$major_edit_char_count = $default_major_edit_char_count;
if ($_GET['articles_per_section'] != '' && is_numeric($_GET['articles_per_section']) && $_GET['articles_per_section'] >= 0) {
    $major_edit_char_count = $_GET['major_edit_char_count'];
}
$offset = 0;
if ($_GET['offset'] != '' && is_numeric($_GET['offset']) && $_GET['offset'] >= 0) {
    $offset = $_GET['offset'];
}
$limit = 100;
if ($_GET['limit'] != '' && is_numeric($_GET['limit']) && $_GET['limit'] > 0) {
    $limit = $_GET['limit'];
}
$start_timestamp = null;
if ($_GET['start_timestamp'] != '') {
    $start_timestamp = string_to_timestamp($_GET['start_timestamp']);
}
$end_timestamp = null;
if ($_GET['end_timestamp'] != '') {
    $end_timestamp = string_to_timestamp($_GET['end_timestamp']);
}
if (is_null($end_timestamp)) {
  /* Set to current time - this is important for two reasons:
     1. So the listing doesn't change as the user browses pages.
     2. So the link at the bottom produces the same results in
        the future (is a permalink).
  */
  $end_timestamp = date("YmdHis"); 
}
$hide_reverts = null;
if ($_GET['hide_reverts']) {
    $hide_reverts = 'on';
}
$hide_minor_edits = null;
if ($_GET['hide_minor_edits']) {
    $hide_minor_edits = 'on';
}

$valid_output['html'] = true;
$valid_output['wiki'] = true;
$output = 'html';
if (!empty($_GET['output']) && $valid_output[$_GET['output']]) {
  $output = $_GET['output'];
}

/* This condition, based on the filter parameters, is applied to all queries
   over the diffinfo database. Sort of a virtual view. */
$survey_articles_query_where =
  "diffinfo_user = " . $user_id . " " .
  ($user_id == 0 ? ("AND diffinfo_user_text = '" . mysql_real_escape_string($user_name) . "' ") : '') .
  ($hide_minor_edits ? 'AND diffinfo_change >= ' . mysql_real_escape_string($major_edit_char_count) : '') . ' ' .
  ($hide_reverts ? 'AND diffinfo_is_revert = 0 ' : '') .
  (is_null($start_timestamp) ? '' : 'AND diffinfo_timestamp >= ' . mysql_real_escape_string($start_timestamp)) . ' ' .
  (is_null($end_timestamp) ? '' : 'AND diffinfo_timestamp <= ' . mysql_real_escape_string($end_timestamp)) . ' ';

# Determine the number of results in this listing and the min/max timestamp.
$result = query(
  "SELECT COUNT(DISTINCT diffinfo_page) as count," . " " .
         "MIN(diffinfo_timestamp) as min_time," . " " .
         "MAX(diffinfo_timestamp) as max_time" . " " .
  "FROM " . $diff_info_table . " AS diffinfo " .
  "WHERE " . $survey_articles_query_where);
$row = mysql_fetch_array($result);
$result_count = $row['count'];

# Generate GET URLs for the first/prev/next/last page buttons
# TODO: Cleaner way to do this? Named parameters?
$get_params_current = get_params($user_name, $hide_reverts, $hide_minor_edits, $articles_per_section, $major_edit_char_count, $offset, $limit, $start_timestamp, $end_timestamp);
$get_params_first = get_params($user_name, $hide_reverts, $hide_minor_edits, $articles_per_section, $major_edit_char_count, 0, $limit, $start_timestamp, $end_timestamp);
$get_params_prev = get_params($user_name, $hide_reverts, $hide_minor_edits, $articles_per_section, $major_edit_char_count, ($offset - $limit < 0) ? 0 : $offset - $limit, $limit, $start_timestamp, $end_timestamp);
$get_params_last = get_params($user_name, $hide_reverts, $hide_minor_edits, $articles_per_section, $major_edit_char_count, ((int)(($result_count - 1) / $limit)) * $limit, $limit, $start_timestamp, $end_timestamp);
$get_params_next = get_params($user_name, $hide_reverts, $hide_minor_edits, $articles_per_section, $major_edit_char_count, $offset + $limit, $limit, $start_timestamp, $end_timestamp);

/* Output headers for content type, character set, and to make the wikitext
   begin downloading right away (Content-Disposition). */
if ($output == 'wiki') {
  header("Content-Type: text/plain; charset=utf-8");
  header('Content-Disposition: attachment; filename="' . htmlspecialchars($user_name) . '.' . ($offset + 1) . "-" . ($offset + 1 + $limit) . ".txt" . '"');
  echo json_decode('"\uFEFF"');
} else if ($output == 'html') {
  header("Content-Type: text/html; charset=utf-8");
}

/* If we're printing the HTML page, print a pretty header with a title, some
   handy links regarding the user, the filtering form, and the page browsing
   buttons. */

if ($output == 'html') {
  echo '<html>' . "\r\n";
  echo '<head><title>Contribution survey for ' . htmlspecialchars($user_name) . ' (articles ' . ($offset + 1) . "-" . ($offset + 1 + $limit - 1) . ')</title></head>' . "\r\n";
  echo '<body>';
  if ($revs_retrieved > 0) {
    $time_delta = microtime_float() - $time_start;
    echo '<p><small>Added ' . $revs_retrieved . ' new revisions in ' . $time_delta . ' sec.</small></p>';
  }

  # Title
  echo '<h1>Contribution survey for ' . htmlspecialchars($user_name) . ' (articles ' . ($offset + 1) . "-" . min(($offset + 1) + $limit - 1, $result_count) . ')</h1>' . "\r\n";

  # Print convenience links related to this user.
  # Based on Template:User5 (http://en.wikipedia.org/wiki/Template:User5)
  echo '<p><a href="http://en.wikipedia.org/wiki/User:' . $user_name_url . '">' . htmlspecialchars($user_name) . '</a> ';
  echo '(';
  echo '<a href="http://en.wikipedia.org/wiki/User talk:' . $user_name_url . '">' . talk . '</a>&nbsp;<b>&middot;</b> ';
  echo '<a href="http://en.wikipedia.org/wiki/Special:Contributions/' . $user_name_url . '">' . contribs . '</a>&nbsp;<b>&middot;</b> ';
  echo '<a href="http://en.wikipedia.org/wiki/Special:DeletedContributions/' . $user_name_url . '">' . 'deleted&nbsp;contribs' . '</a>&nbsp;<b>&middot;</b> ';
  echo '<a href="http://en.wikipedia.org/wiki/Special:Log/move?user=' . $user_name_url . '">' . 'page&nbsp;moves' . '</a>&nbsp;<b>&middot;</b> ';
  echo '<a href="http://en.wikipedia.org/wiki/Special:Blockip/' . $user_name_url . '">' . 'block&nbsp;user' . '</a>&nbsp;<b>&middot;</b> ';
  echo '<a href="http://en.wikipedia.org/w/index.php?title=Special:Log&type=block&page=User:' . $user_name_url . '">' . 'block&nbsp;log' . '</a>&nbsp;<b>&middot;</b> ';
  echo '<a href="http://en.wikipedia.org/wiki/Wikipedia:Requests for checkuser/Case/' . $user_name_url . '">' . 'rfcu' . '</a>&nbsp;<b>&middot;</b> ';
  echo '<a href="http://en.wikipedia.org/wiki/Wikipedia:Contributor_copyright_investigations/' . $user_name_url . '">' . 'cci' . '</a>';
  echo ')</p>';
}
if ($output == 'wiki') {
  echo '{{user5|' . $user_name . '}}' . "\r\n\r\n";
}

/* Print filter form to select parameters to filter the contribution
   list by (begin/end timestamp, hide reverts, hide minor edits, etc). */
if ($output == 'html') {
  echo '<hr />';
  echo '<p><form name="filter" action="survey.php" method="get">';
  echo 'Start timestamp: <input type="text" name="start_timestamp" value="' . $start_timestamp . '" /> ';
  echo 'End timestamp: <input type="text" name="end_timestamp" value="' . $end_timestamp . '" />';
  echo '<br />';

  echo 'Articles per page: <input type="text" name="limit" value="' . $limit . '" /> ';
  echo 'Articles per section: <input type="text" name="articles_per_section" value="' . $articles_per_section . '" />';
  echo '<br />';

  echo '<input type="checkbox" name="hide_reverts" ' . ($hide_reverts ? 'checked' : '') . '/> Hide reverts ';
  echo '<input type="checkbox" name="hide_minor_edits" ' . ($hide_minor_edits ? 'checked' : '') . '/> Hide minor edits (where a minor edit changes at most <input type="text" name="major_edit_char_count" value="' . $major_edit_char_count . '" /> characters) ';
  echo '<input type="submit" value="Update" />';

  echo '<input type="hidden" name="user" value="' . htmlspecialchars($user_name) . '" />';
  echo '<input type="hidden" name="offset" value="' . $offset . '" />';
  echo '</form></p>';
  echo '<hr />';
}

# Links back to home page, to download in wiki format, and page browsing.
$links_bar =
  '<p>' .
  '<a href="index.php">Survey another user</a>' .
  ' | ' .
  '<a href="survey.php?' . $get_params_current . '&output=wiki">Download as wikitext</a>' .
  '</p>' .
  '<p>' .
  ($offset > 0 ? '<a href="survey.php?' . $get_params_first . '">First</a>' : 'First') .
  ' | ' .
  ($offset > 0 ? '<a href="survey.php?' . $get_params_prev . '">Prev</a>' : 'Prev') .
  ' | ';

/* Show numbered pages between Prev and Next like Google. The calculations
   are a bit tricky here - we want at most $limit results on each page,
   every result on a page, and no page with zero results. */
$currentPage = (int)(($offset + $limit - 1)/$limit) + 1;
$minPage = 1;
$maxPage = (int)(($result_count + ($offset % $limit) + $limit - 1)/$limit);
$minPagesShown = 20;
$startPageNum = max(min($maxPage - ($minPagesShown - 1),
                        $currentPage - ((int)($minPagesShown/2))), $minPage);
$endPageNum = min(max($minPage + ($minPagesShown - 1),
                      $currentPage + ((int)($minPagesShown - 1)/2)), $maxPage);
if ($startPageNum > 1) { $links_bar .= '... '; }
for ($pageNum  = $startPageNum; $pageNum <= $endPageNum; $pageNum++)
{
  $get_params_link = get_params($user_name, $hide_reverts, $hide_minor_edits, $articles_per_section, $major_edit_char_count, max($offset + $limit*($pageNum - $currentPage), 0), $limit, $start_timestamp, $end_timestamp);
  $links_bar .= ($pageNum == $currentPage) ? '<b>' . $pageNum . '</b> '
                  : '<a href="survey.php?' . $get_params_link . '">' . $pageNum . '</a> ';
}  
if ($endPageNum < $maxPage) { $links_bar .= '... '; }

$links_bar .=
  '| ' .
  ($offset + $limit < $result_count ? '<a href="survey.php?' . $get_params_next . '">Next</a>' : 'Next') .
  ' | ' .
  ($offset + $limit < $result_count ? '<a href="survey.php?' . $get_params_last . '">Last</a>' : 'Last') .
  '</p>';

if ($output == 'html') {
  echo $links_bar;
}

# Print number of contributions and timestamp range
if ($result_count > 0) {
  if ($output == 'html') {
    echo '<p>';
  }
  echo 'This report covers contributions to ' . $result_count . ' articles from timestamp ' . timestamp_to_string($row['min_time']) . ' to timestamp ' . timestamp_to_string($row['max_time']) . '.' . "\r\n\r\n";
  if ($output == 'html') {
    echo '</p>';
  }
}

/* Query for all pages edited by this user matching the filter parameters,
   ordered by the size of the maximum change amount. This is the main way in
   which Contribution Surveyor brings to the front more likely copyvio. */
$no_results = 1;
$result = query("SELECT diffinfo_page, page_title, COUNT(*) AS count, " .
		"MAX(diffinfo_change) AS max_change " .
		"FROM " . $diff_info_table . " AS diffinfo, page " .
		"WHERE diffinfo_page = page.page_id " .
		"AND " . $survey_articles_query_where . ' ' .
		"GROUP BY diffinfo_page " .
		"ORDER BY MAX(diffinfo_change) DESC " .
		"LIMIT " . $offset . "," . $limit);
$pageNum = $offset + 1;
while ($row = mysql_fetch_array($result)) {
  $no_results = 0;

  # Print a section heading if needed
  if ($articles_per_section == 1 || ($pageNum % $articles_per_section) == ($offset % $articles_per_section) + 1) {
    if ($pageNum > $offset + 1) {
      # Close the old bulleted list
      if ($output == 'html') echo '</ul>';
      echo "\r\n";
    }
    $section_end_page_num = min($result_count, min($pageNum + $articles_per_section - 1, ($offset + 1) + $limit - 1));
    if (!($pageNum == $offset + 1 && $section_end_page_num == ($offset + 1) + $limit - 1)) {
      if ($articles_per_section == 1) {
	if ($output == 'html') {
	  echo "<h2>Article " . $pageNum . "</h2>\r\n\r\n";
          # Open a new bulleted list
	  echo '<ul>';
	} else if ($output == 'wiki') {
	  echo "== Article " . $pageNum . " ==\r\n\r\n";
	}
      }
      else if ($output == 'html') {
	echo "<h2>Articles " . $pageNum . " through " . $section_end_page_num . "</h2>\r\n\r\n";
        # Open a new bulleted list
	echo '<ul>';
      } else if ($output == 'wiki') {
	echo "== Articles " . $pageNum . " through " . $section_end_page_num . " ==\r\n\r\n";
      }
    }
  }
  $pageNum++;

  /* Fetch all edits matching the filter parameters by this user to
     the current page in $row in order to list diffs. Cache diffs in
     a temporary variable so we can compute number of major edits to
     show immediately before it. */
  $bold = 0;
  $major_edits = 0;
  $query = "SELECT diffinfo_rev, diffinfo_prev_rev, diffinfo_change " .
           "FROM " . $diff_info_table . ' ' .
           "WHERE diffinfo_page=" . $row['diffinfo_page'] . ' ' .
           "AND " . $survey_articles_query_where . ' ' .
           "ORDER BY diffinfo_rev";
  $result2 = query($query);
  $edits_text = '';
  $is_creator = 0;
  while ($row2 = mysql_fetch_array($result2)) {
    # Count major edits for this page for printing later
    if ($row2['diffinfo_change'] >= $major_edit_char_count) {
      $major_edits++;
    }

    # Toggle bold on for major edits, off for minor edits
    if (!$bold && ($row2['diffinfo_change'] >= $major_edit_char_count ? 1 : 0)) {
      if ($output == 'html') {
	$edits_text .= "<b>";
      } else if ($output == 'wiki') {
	$edits_text .= "'''";
      }
      $bold = 1;
    }
    if ($bold && ($row2['diffinfo_change'] < $major_edit_char_count ? 1 : 0)) {
      if ($output == 'html') {
	$edits_text .= "</b>";
      } else if ($output == 'wiki') {
	$edits_text .=  "'''";
      }
      $bold = 0;
    }

    /* Print the diff. We use Template:Dif on En wiki in order to keep the
       wikitext small - if you decide to use complete URLs or create your own
       template, remember the <span class="plainlinks">. */
    $diff_url = "http://en.wikipedia.org/w/index.php?diff=" . $row2['diffinfo_rev'];
    if (!is_null($row2['diffinfo_prev_rev']))
    {
      $diff_url = $diff_url . "&oldid=prev";
    }
    else
    {
      $is_creator = 1;
    }
    if ($output == 'html') {
      $edits_text .= '<a href="' . $diff_url . '">(' . ($row2['diffinfo_change'] > 0 ? '+' : '') . $row2['diffinfo_change'] . ')</a>';
    } else if ($output == 'wiki') {
      $edits_text .= '{{dif|' . $row2['diffinfo_rev'] . '|(' . ($row2['diffinfo_change'] > 0 ? '+' : '') . $row2['diffinfo_change'] . ')}}';
    }
  }

  # Unset bold at end
  if ($bold) {
    if ($output == 'html') {
      $edits_text .= "</b>";
    } else if ($output == 'wiki') {
      $edits_text .= "'''";
    }
  }

  /* Print item in bulleted list - includes page title with link to page
    (using spaces instead of _ for readability) and also the number of
    edits and number of major edits in the list. */
  $page_title = $row['page_title'];
  $pretty_page_title = preg_replace('/_/', ' ', $page_title);
  if ($output == 'html') {
    echo '<li>';
    if ($is_creator) { echo '<b>N</b> '; }
    echo '<a href="http://en.wikipedia.org/wiki/' . $page_title . '">' . $pretty_page_title . '</a>: ';
    echo '(' . $row['count'] . ' edits, ' . $major_edits . ' major, ' . ($row['max_change'] > 0 ? '+' : '') . $row['max_change'] . ') ';
  } else if ($output == 'wiki') {
    echo '* ';
    if ($is_creator) { echo '\'\'\'N\'\'\' '; }
    echo '[[:' . $pretty_page_title . ']]: ';
    echo '(' . $row['count'] . ' edits, ' . $major_edits . ' major, ' . ($row['max_change'] > 0 ? '+' : '') . $row['max_change'] . ') ';
  }
  # Print list of diffs
  echo $edits_text;
  # Close the list item
  if ($output == 'html') {
    $edits_text .= '</li>';
  }
  echo "\r\n";
}

# Close database connection
mysql_close($db);
unset($toolserver_mycnf);

/* Print special message if no results found. This should only happen if the
   user has no contributions or someone hacked in custom GET parameters. */
if ($no_results) {
  echo "No results found.\r\n";
} else {
  if ($output == 'html') echo '</ul>';
}

/* Print a message at the bottom giving a link to the URL that produced this
   report, a timestamp, and the time it ran for, for reference and
   diagnostics. */
$time_delta = microtime_float() - $time_start;
if ($output == 'html') {
  echo $links_bar;

  printf('<p><small>This report generated by <a href="http://toolserver.org/~dcoetzee/contributionsurveyor/survey.php?' . $get_params_current . '">Contribution Surveyor</a> at ' . date('c')  . ' in %0.2f sec.</small></p>', $time_delta);

  echo '</body>';
  echo '</html>';
} else {
  printf("\r\n" . 'This report generated by [http://toolserver.org/~dcoetzee/contributionsurveyor/survey.php?' . $get_params_current . '&output=wiki Contribution Surveyor] at ' . date('c') . ' in %0.2f sec.' . "\r\n", $time_delta);
}

?>