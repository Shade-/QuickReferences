<?php
/**
 * Quick references
 * 
 * Lets user refer to threads by typing "#".
 *
 * @package QuickReferences
 * @author  Shade <legend_k@live.it>
 * @license MIT https://opensource.org/licenses/MIT
 * @version 1.2
 */

if (!defined('IN_MYBB')) {
	die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

if (!defined("PLUGINLIBRARY")) {
	define("PLUGINLIBRARY", MYBB_ROOT . "inc/plugins/pluginlibrary.php");
}

function quickreferences_info()
{
	return array(
		'name' => 'QuickReferences',
		'description' => 'Quickly refer to any thread by typing "#" and searching for the thread\'s title.',
		'website' => 'http://www.mybboost.com',
		'author' => 'Shade',
		'version' => '1.2',
		'compatibility' => '18*'
	);
}

function quickreferences_is_installed()
{
	global $cache;
	
	$info = quickreferences_info();
	$installed = $cache->read("shade_plugins");
	if ($installed[$info['name']]) {
		return true;
	}
}

function quickreferences_install()
{
	global $cache, $PL;
	
	if (!file_exists(PLUGINLIBRARY)) {
		flash_message('QuickReferences needs PluginLibrary in order to be installed. The installation has been aborted.', "error");
		admin_redirect("index.php?module=config-plugins");
	}
	
	$PL or require_once PLUGINLIBRARY;
	
	// Add stylesheets
	$PL->stylesheet('at.css', file_get_contents(dirname(__FILE__) . '/QuickReferences/stylesheets/at.css'));
	
	// Add the plugin to our cache
	$info = quickreferences_info();
	$shade_plugins = $cache->read('shade_plugins');
	$shade_plugins[$info['name']] = [
		'title' => $info['name'],
		'version' => $info['version']
	];
	$cache->update('shade_plugins', $shade_plugins);
}

function quickreferences_uninstall()
{
	global $cache, $PL;
	
	if (!file_exists(PLUGINLIBRARY)) {
		flash_message('QuickReferences needs PluginLibrary in order to be uninstalled. The uninstallation has been aborted.', "error");
		admin_redirect("index.php?module=config-plugins");
	}
	
	$PL or require_once PLUGINLIBRARY;
	
    // Remove stylesheets
	$PL->stylesheet_delete('at');
	
	$info = quickreferences_info();
	
	// Delete the plugin from cache
	$shade_plugins = $cache->read('shade_plugins');
	unset($shade_plugins[$info['name']]);
	$cache->update('shade_plugins', $shade_plugins);
	
}

$plugins->add_hook('xmlhttp', 'quickreferences_search_threads');
$plugins->add_hook('parse_message_start', 'quickreferences_parse_message', 1); // Priority over other plugins as some may add placeholders with # chars (eg.: DVZMentions)
$plugins->add_hook('xmlhttp_update_post', 'quickreferences_quick_edit');
$plugins->add_hook('postbit', 'quickreferences_quick_reply');
$plugins->add_hook('pre_output_page', 'quickreferences_load_scripts');

// Search threads
function quickreferences_search_threads()
{
	global $db, $mybb;

	if ($mybb->input['action'] != 'get_threads') return false;
	
	require_once MYBB_ROOT . 'inc/functions_search.php';
	
	// Moderators can view unapproved threads
	$query = $db->simple_select("moderators", "fid, canviewunapprove, canviewdeleted", "(id='{$mybb->user['uid']}' AND isgroup='0') OR (id='{$mybb->user['usergroup']}' AND isgroup='1')");
	
	if ($mybb->usergroup['issupermod'] == 1) {
		$unapproved_where = "t.visible>=-1"; // Super moderators (and admins)
	}
	else if ($db->num_rows($query)) {
		
		// Normal moderators
		$unapprove_forums = $deleted_forums = [];
		$unapproved_where = '(t.visible = 1';
		
		while ($moderator = $db->fetch_array($query)) {
			
			if ($moderator['canviewunapprove'] == 1) {
				$unapprove_forums[] = $moderator['fid'];
			}

			if ($moderator['canviewdeleted'] == 1) {
				$deleted_forums[] = $moderator['fid'];
			}
			
		}

		if (!empty($unapprove_forums)) {
			$unapproved_where .= " OR (t.visible = 0 AND t.fid IN(".implode(',', $unapprove_forums)."))";
		}
		
		if (!empty($deleted_forums)) {
			$unapproved_where .= " OR (t.visible = -1 AND t.fid IN(".implode(',', $deleted_forums)."))";
		}
		
		$unapproved_where .= ')';
		
	}
	else {
		$unapproved_where = 't.visible>0'; // Normal users
	}
	
	// Exclude inactive and unsearcheable forums
	$permsql = '';
	
	$group_permissions = forum_permissions();
	foreach ($group_permissions as $fid => $forum_permissions) {
		
		if (isset($forum_permissions['canonlyviewownthreads']) and $forum_permissions['canonlyviewownthreads'] == 1) {
	    	$onlyusfids[] = $fid;
		}
		
	}
	
	if (!empty($onlyusfids)) {
		$permsql .= "AND ((t.fid IN(".implode(',', $onlyusfids).") AND t.uid='{$mybb->user['uid']}') OR t.fid NOT IN(".implode(',', $onlyusfids)."))";
	}
	
	$unsearchforums = get_unsearchable_forums();
	if ($unsearchforums) {
		$permsql .= " AND t.fid NOT IN ($unsearchforums)";
	}
	
	$inactiveforums = get_inactive_forums();
	if ($inactiveforums) {
		$permsql .= " AND t.fid NOT IN ($inactiveforums)";
	}
	
	$visible = ($mybb->user['issupermod'] == 1) ? '-1' : '0';

	$query = $db->query("
		SELECT t.subject, t.tid, f.name AS forumname, u.avatar
		FROM ".TABLE_PREFIX."threads t
		LEFT JOIN ".TABLE_PREFIX."forums f ON t.fid = f.fid
		LEFT JOIN ".TABLE_PREFIX."users u ON u.uid = t.uid
		WHERE t.subject LIKE '%" . $db->escape_string($mybb->input['query']) . "%' AND t.closed NOT LIKE 'moved|%' AND {$unapproved_where} {$permsql}
		ORDER BY t.sticky DESC, t.lastpost
		LIMIT 5
	");

	while ($thread = $db->fetch_array($query)) {
		
		// Fixes http://www.mybboost.com/thread-avatars-not-displayed-properly-if-using-default-avatars
		$thread['avatar'] = ($thread['avatar']) ? $thread['avatar'] : 'images/default_avatar.png';
		
		$threads[] = $thread;
		
	}
	
	echo json_encode($threads);
}

// Parse message and create placeholders
function quickreferences_parse_message(&$message)
{
	global $quickreferences_tids;
	
	// Fixes http://www.mybboost.com/thread-color-mycode-broken
	$message = preg_replace_callback('/(?<!color\=)#([0-9]+)/', function(&$matches) {
		
		global $quickreferences_tids;
	
		if ($matches[1]) {
			
			$quickreferences_tids[] = $matches[1];
		
			return '<QUICKREFERENCES#' . $matches[1] . '>';
		
		}
		
	}, $message);
	
}

// Load scripts and fill placeholders if found
function quickreferences_load_scripts(&$content)
{	
	$content = quickreferences_fill_placeholders($content);
	
	$thirdparty = '';
	
	// Rin Editor support
	if ($GLOBALS['cache']->cache['plugins']['active']['rineditor']) {
		$thirdparty = "<script type='text/javascript' src='jscripts/util/thirdparty/rineditor.js'></script>";
	}
	
	$thirdparty = $GLOBALS['plugins']->run_hooks('quickreferences_third_party', $thirdparty);
	
	if (in_array(THIS_SCRIPT, ['showthread.php', 'newreply.php', 'newthread.php', 'editpost.php', 'private.php']) or defined('IN_CHAT')) {
	
		$scripts = <<<HTML
	<script type='text/javascript' src='jscripts/util/caret.js'></script>
	<script type='text/javascript' src='jscripts/util/at.js'></script>
	<script type='text/javascript' src='jscripts/util/rules.js'></script>
	{$thirdparty}
	<script type='text/javascript'>
		$(document).ready(function() {
			QuickReferences.init();
		});
	</script>
HTML;
		
		$content = str_replace('</body>', $scripts . '</body>', $content);
	
	}
	
	return $content;
}

function quickreferences_quick_edit()
{
	$GLOBALS['post']['message'] = quickreferences_fill_placeholders($GLOBALS['post']['message']);
}

function quickreferences_quick_reply(&$post)
{
	if ($GLOBALS['mybb']->input['ajax']) {	
		$post['message'] = quickreferences_fill_placeholders($post['message']);
	}
}

// Fill placeholders
function quickreferences_fill_placeholders($content)
{
	global $quickreferences_tids;
	static $threads;
	
	if ($quickreferences_tids) {
		
		if (!$threads) {
		
			global $db;
			
			// Escape values
			array_walk($quickreferences_tids, function (&$tid) {
				$tid = (int) $tid;
			});
			
			$quickreferences_tids = array_unique($quickreferences_tids);
	
			$query = $db->simple_select('threads', 'tid, subject', "tid IN ('" . implode("','", $quickreferences_tids) . "')");
			while ($thread = $db->fetch_array($query)) {
				$threads[$thread['tid']] = "<a class='quick_reference' href='" . get_thread_link($thread['tid']) . "'>" . $thread['subject'] . "</a>";
			}
			
		}
	
		$content = preg_replace_callback('/(?:&lt;|\<)QUICKREFERENCES#([0-9]+)(?:&gt;|\>)/', function(&$match) use ($threads) {
			
			if ($match[1] and $threads[$match[1]]) {
				return $threads[$match[1]];
			}
			else {
				return '#' . $match[1]; // Restore the original string if it's not a valid thread
			}
			
		}, $content);
		
	}
	
	return $content;
}