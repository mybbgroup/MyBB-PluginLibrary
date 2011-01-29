<?php
/**
 * This file is part of PluginLibrary for MyBB.
 * Copyright (C) 2011 Andreas Klauer <Andreas.Klauer@metamorpher.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

/* --- Plugin API: --- */

function pluginlibrary_info()
{
    return array(
        "name"          => "PluginLibrary",
        "description"   => "A collection of useful functions for other plugins.",
        "website"       => "https://github.com/frostschutz/PluginLibrary",
        "author"        => "Andreas Klauer",
        "authorsite"    => "mailto:Andreas.Klauer@metamorpher.de",
        "version"       => "1",
        "guid"          => "839e9d72e2875a51fccbc0257dfeda03",
        "compatibility" => "*"
        );
}

function pluginlibrary_is_installed()
{
    // Don't try this at home.
    return false;
}

function pluginlibrary_install()
{
    // Avoid unnecessary activation as a plugin with a friendly success message.
    flash_message("The selected plugin does not have to be activated.", 'success');
    admin_redirect("index.php?module=config-plugins");
}

function pluginlibrary_uninstall()
{
}

function pluginlibrary_activate()
{
}

function pluginlibrary_deactivate()
{
}

/* --- PluginLibrary class: --- */

class PluginLibrary
{
    /**
     * Version number.
     */
    public $version = 0;

    /**
     * Cache handler.
     */
    public $cachehandler;

    /* --- Setting groups and settings: --- */

    /**
     * Create and/or update setting group and settings.
     *
     * @param string Internal unique group name and setting prefix.
     * @param string Group title that will be shown to the admin.
     * @param string Group description that will show up in the group overview.
     * @param array The list of settings to be added to that group.
     * @param bool Generate language file. (Developer option, default false)
     */
    function settings($name, $title, $description, $list, $makelang=false)
    {
        global $db;

        /* Setting group: */

        if($makelang)
        {
            header("Content-Type: text/plain; charset=UTF-8");
            echo "<?php\n/**\n * Settings language file generated by PluginLibrary.\n *\n */\n\n";
            echo "\$l['setting_group_{$name}'] = \"".addcslashes($title, '\\"$')."\";\n";
            echo "\$l['setting_group_{$name}_desc'] = \"".addcslashes($description, '\\"$')."\";\n";
        }

        // Group array for inserts/updates.
        $group = array('name' => $db->escape_string($name),
                       'title' => $db->escape_string($title),
                       'description' => $db->escape_string($description));

        // Check if the group already exists.
        $query = $db->simple_select("settinggroups", "gid", "name='${group['name']}'");

        if($row = $db->fetch_array($query))
        {
            // We already have a group. Update title and description.
            $gid = $row['gid'];
            $db->update_query("settinggroups", $group, "gid='{$gid}'");
        }

        else
        {
            // We don't have a group. Create one with proper disporder.
            $query = $db->simple_select("settinggroups", "MAX(disporder) AS disporder");
            $row = $db->fetch_array($query);
            $group['disporder'] = $row['disporder'] + 1;
            $gid = $db->insert_query("settinggroups", $group);
        }

        /* Settings: */

        // Deprecate all the old entries.
        $db->update_query("settings",
                          array("description" => "PLUGINLIBRARYDELETEMARKER"),
                          "gid='$gid'");

        // Create and/or update settings.
        foreach($list as $key => $setting)
        {
            // Prefix all keys with group name.
            $key = "{$name}_{$key}";

            if($makelang)
            {
                echo "\$l['setting_{$key}'] = \"".addcslashes($setting['title'], '\\"$')."\";\n";
                echo "\$l['setting_{$key}_desc'] = \"".addcslashes($setting['description'], '\\"$')."\";\n";
            }

            // Escape input values.
            $vsetting = array_map(array($db, 'escape_string'), $setting);

            // Add missing default values.
            $disporder += 1;

            $setting = array_merge(
                array('optionscode' => 'yesno',
                      'value' => '0',
                      'disporder' => $disporder),
                $setting);

            $setting['name'] = $db->escape_string($key);
            $setting['gid'] = $gid;

            // Check if the setting already exists.
            $query = $db->simple_select('settings', 'sid',
                                        "gid='$gid' AND name='{$setting['name']}'");

            if($row = $db->fetch_array($query))
            {
                // It exists, update it, but keep value intact.
                unset($setting['value']);
                $db->update_query("settings", $setting, "sid='{$row['sid']}'");
            }

            else
            {
                // It doesn't exist, create it.
                $db->insert_query("settings", $setting);
            }
        }

        if($makelang)
        {
            echo "\n?>\n";
            exit;
        }

        // Delete deprecated entries.
        $db->delete_query("settings",
                          "gid='$gid' AND description='PLUGINLIBRARYDELETEMARKER'");

        // Rebuild the settings file.
        rebuild_settings();
    }

    /**
     * Delete setting groups and settings.
     *
     * @param string Internal unique group name.
     * @param bool Also delete groups starting with name_.
     */
    function settings_delete($name, $greedy=false)
    {
        global $db;

        $name = $db->escape_string($name);
        $where = "name='{$name}'";

        if($greedy)
        {
            $where .= " OR name LIKE '{$name}_%'";
        }

        // Query the setting groups.
        $query = $db->simple_select('settinggroups', 'gid', $where);

        // Delete the group and all its settings.
        while($gid = $db->fetch_field($query, 'gid'))
        {
            $db->delete_query('settinggroups', "gid='{$gid}'");
            $db->delete_query('settings', "gid='{$gid}'");
        }

        // Rebuild the settings file.
        rebuild_settings();
    }

    /* --- Cache: --- */

    /**
     * Obtain a non-database cache handler.
     */
    function _cache_handler()
    {
        global $cache;

        if(is_object($cache->handler))
        {
            return $cache->handler;
        }

        if(is_object($this->cachehandler))
        {
            return $this->cachehandler;
        }

        // Fall back to disk handler.
        require_once MYBB_ROOT.'/inc/cachehandlers/disk.php';
        $this->cachehandler = new diskCacheHandler();
        return $this->cachehandler;
    }

    /**
     * Read on-demand cache.
     */
    function cache_read($name)
    {
        global $cache;

        if(isset($cache->cache[$name]))
        {
            return $cache->cache[$name];
        }

        $handler = $this->_cache_handler();
        $contents = $handler->fetch($name);
        $cache->cache[$name] = $contents;

        return $contents;
    }

    /**
     * Write on-demand cache.
     */
    function cache_update($name, $contents)
    {
        global $cache;

        $handler = $this->_cache_handler();
        $cache->cache[$name] = $contents;

        return $handler->put($name, $contents);
    }

    /**
     * Delete cache.
     *
     * @param string Cache name or title.
     * @param bool Also delete caches starting with name_.
     */
    function cache_delete($name, $greedy=false)
    {
        global $db, $cache;

        // Prepare for database query.
        $dbname = $db->escape_string($name);
        $where = "title='{$dbname}'";

        // Delete on-demand or handler cache.
        $handler = $this->_cache_handler();
        $handler->delete($name);

        // Greedy?
        if($greedy)
        {
            // Collect possible additional names...

            // ...from the currently loaded cache...
            $keys = array_keys($cache->cache);
            $name .= '_';

            foreach($keys as $key)
            {
                if(strpos($key, $name) === 0)
                {
                    $names[$key] = 0;
                }
            }

            // ...from the database...
            $where .= " OR title LIKE '{$name}_%'";
            $query = $db->simple_select('datacache', 'title', $where);

            while($row = $db->fetch_array($query))
            {
                $names[$row['title']] = 0;
            }

            // ...and delete them all.
            foreach($names as $key=>$val)
            {
                $handler->delete($key);
            }
        }

        // Delete database caches too.
        $db->delete_query('datacache', $where);
    }

    /* --- Corefile edits: --- */

    /**
     * insert comment at the beginning of each line
     */
    function _comment($comment, $code)
    {
        if(!strlen($code))
        {
            return "";
        }

        if(substr($code, -1) == "\n")
        {
            $code = substr($code, 0, -1);
        }

        $code = str_replace("\n", "\n{$comment}", "\n{$code}");

        return substr($code, 1)."\n";
    }

    /**
     * remove comment at the beginning of each line
     */
    function _uncomment($comment, $code)
    {
        if(!strlen($code))
        {
            return "";
        }

        $code = "\n{$code}";
        $code = str_replace("\n{$comment}", "\n", $code);

        return substr($code, 1);
    }

    /**
     * remove lines with comment at the beginning entirely
     */
    function _zapcomment($comment, $code)
    {
        return preg_replace("#^".preg_quote($comment, "#").".*\n?#m", "", $code);
    }

    /**
     * align start and stop to newline characters in text
     */
    function _align($text, &$start, &$stop)
    {
        // Align start to line boundary.
        $nl = strrpos($text, "\n", -strlen($text)+$start);
        $start = ($nl === false ? 0 : $nl + 1);

        // Align stop to line boundary.
        $nl = strpos($text, "\n", $stop);
        $stop = ($nl === false ? strlen($text) : $nl + 1);
    }

    /**
     * in text find the smallest first match for a series of search strings
     */
    function _match($text, $search, &$start)
    {
        $stop = $start;

        // forward search (determine smallest stop)
        foreach($search as $needle)
        {
            $stop = strpos($text, $needle, $stop);

            if($stop === false)
            {
                // we did not find out needle, so this does not match
                return false;
            }

            $stop += strlen($needle);
        }

        // backward search (determine largest start)
        $start = $stop;

        foreach(array_reverse($search) as $needle)
        {
            $start = strrpos($text, $needle, -strlen($text)+$start);
        }

        return $stop;
    }

    /**
     * dissect text based on a series of edits
     */
    function _dissect($text, &$edits)
    {
        $matches = array();

        foreach($edits as &$edit)
        {
            $search = (array)$edit['search'];
            $start = 0;
            $edit['matches'] = array();

            while(($stop = $this->_match($text, $search, $start)) !== false)
            {
                $pos = $stop;
                $this->_align($text, $start, $stop);

                // to count the matches, and help debugging
                $edit['matches'][] = array($start, $stop,
                                           substr($text, $start, $stop-$start));

                if(isset($matches[$start]))
                {
                    $edit['error'] = 'match collides with another edit';
                    return false;
                }

                else if(count($edit['matches']) > 1 && !$edit['multi'])
                {
                    $edit['error'] = 'multiple matches not allowed for this edit';
                    return false;
                }

                $matches[$start] = array($stop, &$edit);
                $start = $pos;
            }

            if(!count($edit['matches']) && !$edit['none'])
            {
                $edit['error'] = 'zero matches not allowed for this edit';
                return false;
            }
        }

        ksort($matches);
        return $matches;
    }

    /**
     * edit text (perform the actual string modification)
     */
    function _edit($text, &$edits, $ins='/**/', $del='/*/*')
    {
        $matches = $this->_dissect($text, $edits);

        if($matches === false)
        {
            return false;
        }

        $result = array();
        $pos = 0;

        foreach($matches as $start => $val)
        {
            $stop = $val[0];
            $edit = &$val[1];

            if($start < $pos)
            {
                $edit['error'] = 'match overlaps with another edit';
                $previous_edit['error'] = 'match overlaps with another edit';
                return false;
            }

            // Keep previous edit for overlapping detection
            $previous_edit = &$edit;

            // unmodified text before match
            $result[] = substr($text, $pos, $start-$pos);

            // insert before
            $result[] = $this->_comment($ins, $edit['before']);

            // original matched text
            $match = substr($text, $start, $stop-$start);
            $pos = $stop;

            if($edit['replace'] || is_string($edit['replace']))
            {
                // insert match (commented out)
                $result[] = $this->_comment($del, $match);

                // Insert replacement, if it's a string
                if(is_string($edit['replace']) && strlen($edit['replace']))
                {
                    $result[] = $this->_comment($ins, $edit['replace']);
                }

                // make sure something will be inserted afterwards (uncomment)
                else if(!strlen($edit['after']))
                {
                    $edit['after'] = ' ';
                }
            }

            else
            {
                // insert match unmodified
                $result[] = $match;
            }

            // insert after
            $result[] = $this->_comment($ins, $edit['after']);
        }

        // insert rest
        $result[] = substr($text, $pos);

        return implode("", $result);
    }

    /**
     * edit core
     */
    function edit_core($name, $file, $edits=array(), $apply=false)
    {
        $ins = "/* + PL:{$name} + */ ";
        $del = "/* - PL:{$name} - /* ";

        $text = file_get_contents(MYBB_ROOT.$file);
        $result = $text;

        if($text === false)
        {
            return false;
        }

        if(count($edits) && !count($edits[0]))
        {
            $edits = array($edits);
        }

        // Step 1: remove old comments, if present.
        $result = $this->_zapcomment($ins, $result);
        $result = $this->_uncomment($del, $result);

        // Step 2: prevent colliding edits by adding conditions.
        $edits[] = array('search' => array('/* + PL:'),
                         'multi' => true,
                         'none' => true);
        $edits[] = array('search' => array('/* - PL:'),
                         'multi' => true,
                         'none' => true);

        // Step 3: perform edits.
        $result = $this->_edit($result, $edits, $ins, $del);

        if($result === false)
        {
            // edits couldn't be performed
            return false;
        }

        if($result == $text)
        {
            // edit made no changes
            return true;
        }

        // try to write the file
        if($apply && @file_put_contents(MYBB_ROOT.$file, $result) !== false)
        {
            // changes successfully applied
            return true;
        }

        // return the string
        return $result;
    }

    /* --- Group memberships: --- */

    /**
     * is_member
     */
    function is_member($groups, $user=false)
    {
        global $mybb;

        // Default to current user.
        if($user === false)
        {
            $user = $mybb->user;
        }

        else if(is_array($user))
        {
            // do nothing
        }

        else
        {
            // assume it's a UID
            $user = get_user($user);
        }

        // Collect the groups the user is in.
        $memberships = explode(',', $user['additionalgroups']);
        $memberships[] = $user['usergroup'];

        // Convert search to an array of group ids
        if(is_array($groups))
        {
            // already an array, do nothing
        }

        if(is_string($groups))
        {
            $groups = explode(',', $groups);
        }

        else
        {
            // probably a single number
            $groups = (array)$groups;
        }

        // Make sure we're comparing numbers.
        $groups = array_map('intval', $groups);
        $memberships = array_map('intval', $memberships);

        // Remove 0 if present.
        $groups = array_filter($groups);

        // Return the group intersection.
        return array_intersect($groups, $memberships);
    }

    /* --- String functions: --- */

    /**
     * url_append
     */
    function url_append($url, $params, $sep="&amp;", $encode=true)
    {
        if(strpos($url, '?') === false)
        {
            $separator = '?';
        }

        else
        {
            $separator = $sep;
        }

        $append = '';

        foreach($params as $key => $value)
        {
            if($encode)
            {
                $value = urlencode($value);
            }

            $append .= "{$separator}{$key}={$value}";
            $separator = $sep;
        }

        return $url.$append;
    }
}

global $PL;
$PL = new PluginLibrary();

/* --- End of file. --- */
?>
