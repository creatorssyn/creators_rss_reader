<?php
/**
 * Plugin Name: Creators RSS Reader
 * Plugin URI: http://get.creators.com/docs/wiki#wp_plugin
 * Description: A plugin to interface Creators content directly into your WordPress CMS
 * Version: 1.0.0
 * Author: Brandon Telle <btelle@creators.com>
 * Author URI: http://www.creators.com
 * License: MIT
 */

defined( 'ABSPATH' ) OR exit;

class CreatorsRSSParser
{
    private $api_key;
    
    protected static $default_options = array(
        'creators_feed_reader_auto_publish'=>TRUE, 
        'creators_feed_reader_user_id'=>1,
        'creators_feed_reader_api_key'=>'',
        'creators_feed_reader_post_name_pattern'=>'%t', 
        'creators_feed_reader_user_ids'=>'a:0:{}',
        'creators_feed_reader_last_run'=>0,
        'creators_feed_reader_features'=>array()
    );
    
    protected static $instance;
    
    function init() 
    {
        is_null(self::$instance) AND self::$instance = new self;
        
        // Store API Key
        self::$instance->api_key = self::$instance->get_setting('creators_feed_reader_api_key');
        
        // Actions
        add_action('parse_rss_feed', array('CreatorsRSSParser', 'parse_rss_feed'));
        add_action('admin_menu', array('CreatorsRSSParser', 'add_menu_option'));
        add_action('admin_init', array('CreatorsRSSParser', 'register_settings'));
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), array('CreatorsRSSParser', 'plugin_action_links'));
        
        return self::$instance;
    }
    
    /**
     * Activate the plugin
     */
    function activate()
    {
        if (!current_user_can('activate_plugins'))
            return;
        
        // Set up default options
        foreach(self::$default_options as $k=>$v)
        {
            add_option($k, $v);
        }
        
        // Schedule hourly RSS checks
        wp_schedule_event(time(), 'hourly', 'parse_rss_feed');
    }
    
    /**
     * Deactivate the plugin
     */
    function deactivate()
    {
        if (!current_user_can('activate_plugins'))
            return;
        
        // Remove scheduled hook
        wp_clear_scheduled_hook('parse_rss_feed');
    }
    
    /**
     * Uninstall the plugin
     * Should this delete all users and posts? 
     */
    function uninstall()
    {
        if (!current_user_can('activate_plugins'))
            return;
        
        if ( __FILE__ != WP_UNINSTALL_PLUGIN )
            return;
        
        // Remove options
        foreach(self::$default_options as $k=>$v)
        {
            delete_option($k);
        }
    }
    
    /**
     * Read the Creators RSS Feed and post content to the site
     * @return boolean TRUE if feed was read, FALSE if feed cannot be read or hasn't been updated
     */
    function parse_rss_feed()
    {
        if(!function_exists('simplexml_load_file'))
        {
            return FALSE;
        }
        
        if(self::$instance->api_key === '')
            return;
        
        $url = 'http://get.creators.com/feed/'.self::$instance->api_key.'.rss';
        $xml = simplexml_load_file($url);
        
        //var_dump($xml->channel);
        
        if(strtotime($xml->channel->lastBuildDate) <= get_option('creators_feed_reader_last_run'))
        {
            echo "Feed is stale, bye!";
            return FALSE;
        }
        
        update_option('creators_feed_reader_last_run', time());
        
        foreach($xml->channel->item as $item)
        {
            preg_match('/([a-z0-9]{2,4})@/', $item->author, $m);
            if(isset($m[1]) && self::$instance->should_post_feature($m[1]))
            {
                self::$instance->create_post($item);
            }
        }
        
        return TRUE;
    }
    
    
    private function should_post_feature($filecode)
    {
        $users = get_option('creators_feed_reader_features');
        return isset($users[$filecode]) && $users[$filecode] == 'on';
    }
    
    private function create_post($item)
    {
        $post = array();
        $post['post_content'] = (string)$item->description;
        $post['post_name'] = self::$instance->parse_post_name($item);
        $post['post_title'] = (string)substr($item->title[0], 0, strrpos($item->title[0], ' by '));
        $post['post_status'] = self::$instance->get_setting('creators_feed_reader_auto_publish')? 'publish': 'draft';
        $post['post_author'] = self::$instance->get_user_id($item->author);
        $post['post_date'] = date('Y-m-d H:i:s', strtotime($item->pubDate));
        
        var_dump($post);
        
        return wp_insert_post($post);
    }
    
    private function create_user($filecode)
    {
        $response = self::$instance->api_request('/features/details/json/'.$filecode);
        
        if($response != NULL)
        {
            $feature = json_decode($response, TRUE);
            
            if($feature === NULL)
                return FALSE;
            
            $user = array();
            $user['user_login'] = self::$instance->make_username($feature['title']);
            $user['user_pass'] = wp_generate_password();
            $user['user_url'] = 'http://www.creators.com/read/'.$feature['file_code'];
            $user['display_name'] = $feature['title'];
            $user['user_email'] = $feature['file_code'].'@get.creators.com';
            $user['role'] = 'author';
            
            if(isset($feature['authors']) && count($feature['authors']) == 1)
            {
                list($user['first_name'], $user['last_name']) = explode(' ', $feature['authors'][0]['name'], 2);
                $user['description'] = strip_tags(str_replace('</p>', "\r\n\r\n", $feature['authors'][0]['bio']));
            }
            
            $id = wp_insert_user($user);
            
            if(is_int($id))
            {
                $users = get_option('creators_feed_reader_user_ids');
                $users[$filecode] = $id;
                
                update_option('creators_feed_reader_user_ids', $users);
                
                return TRUE;
            }
            else
            {
                return $id;
            }
        }
        
        return FALSE;
    }
    
    // Replace with Creators_PHP function calls
    private function api_request($url, $post_data=array())
    {
        $ch = curl_init('http://get.creators.com/api'.$url);
     
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, 
            array('X_API_KEY: '.self::$instance->api_key, 
                  'X_API_VERSION: 0.31'));

        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if(!empty($post_data))
        {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        }

        return curl_exec($ch);
    }
    
    /**
     * Get a user's ID from their Creators file code
     * @param string $author_string The author attribute of an RSS item
     * @return mixed User ID, or NULL if a user doesn't exist
     */
    private function get_user_id($author_string)
    {
        preg_match('/([a-z0-9]+)@/', $author_string, $m);

        if(isset($m[1]))
        {
            $users = get_option('creators_feed_reader_user_ids');
            if(isset($users[$m[1]]))
                return $users[$m[1]];
        }
        
        return NULL;
    }
    
    /**
     * Create a post URL from a release title and url pattern
     * 
     * @param object $item SimpleXML item object
     * @return string Post URL
     */
    private function parse_post_name($item)
    {
        $slug = self::$instance->get_setting('creators_feed_reader_post_name_pattern');
        preg_match('|/([0-9]+)|', $item->guid, $matches);
        $post_id = $matches[1];
        $post_title = sanitize_title(trim(substr($item->title, 0, strrpos($item->title, ' by '))));
        $post_author = sanitize_title(trim(substr($item->title, strrpos($item->title, ' by ')-1)));
        
        return $post_id.'-'.str_replace(array('%t', '%a'), array($post_title, $post_author), $slug);
    }
    
    /**
     * Make a Wordpress-safe username.
     * 
     * @param string $title Feature title
     * @return string Username
     */
    private function make_username($title)
    {
        return sanitize_user(strtolower(str_replace(' ', '', $title)), TRUE);
    }
    
    /**
     * Settings functions
     */
    private function get_setting($setting_slug)
    {
        return get_option($setting_slug);
    }
    
    function register_settings()
    {
        register_setting('creators_rss', 'creators_feed_reader_api_key');
        register_setting('creators_rss', 'creators_feed_reader_auto_publish');
        register_setting('creators_rss', 'creators_feed_reader_post_name_pattern');
        register_setting('creators_rss', 'creators_feed_reader_features');
        
        add_settings_section('creators_rss_main', 'Main Settings', array('CreatorsRSSParser', 'display_main_text'), 'creators_rss');
        add_settings_field('creators_feed_reader_api_key', 'Your API Key', array('CreatorsRSSParser', 'display_setting_api_key'), 'creators_rss', 'creators_rss_main');
        add_settings_field('creators_feed_reader_auto_publish', 'Publish Automatically', array('CreatorsRSSParser', 'display_setting_auto_publish'), 'creators_rss', 'creators_rss_main');
        add_settings_field('creators_feed_reader_post_name_pattern', 'Post URL Pattern', array('CreatorsRSSParser', 'display_setting_name_pattern'), 'creators_rss', 'creators_rss_main');
        
        $features = json_decode(self::$instance->api_request('/features/get_list/json/NULL/1000'), TRUE);
        
        if($features !== NULL && count($features) > 0)
        {
            add_settings_section('creators_rss_features', 'Add Features', array('CreatorsRSSParser', 'display_features_text'), 'creators_rss');
            foreach($features as $f)
            {
                add_settings_field('creators_feed_reader_features['.$f['file_code'].']', $f['title'], array('CreatorsRSSParser', 'display_setting_author_checkbox'), 'creators_rss', 'creators_rss_features', array($f['file_code']));
            }
        }
    }
    
    function add_menu_option()
    {
        $page_hook = add_submenu_page('options-general.php', 'Creators RSS Feed', 'Creators RSS Feed', 'manage_options', 'creators-rss-feeds-options', array('CreatorsRSSParser', 'display_options_page'));
        add_action('load-'.$page_hook, array('CreatorsRSSParser', 'settings_load_hook'));
    }
    
    function display_main_text()
    {
        echo '<p>General Feed Reader Settings</p>';
    }
    
    function display_features_text()
    {
        echo '<p>General Feed Reader Settings</p>';
    }
    
    function display_setting_name_pattern()
    {
        $option = get_option('creators_feed_reader_post_name_pattern');
        echo "<input id='creators_feed_reader_post_name_pattern' name='creators_feed_reader_post_name_pattern' size='40' type='text' value='{$option}' />";
    }
    
    function display_setting_auto_publish()
    {
        $option = get_option('creators_feed_reader_auto_publish');
        echo "<input id='creators_feed_reader_auto_publish' type='checkbox' name='creators_feed_reader_auto_publish' ".($option?"checked='checked'":"")." />";
    }
    
    function display_setting_api_key()
    {
        $option = get_option('creators_feed_reader_api_key');
        echo "<input id='creators_feed_reader_api_key' name='creators_feed_reader_api_key' size='40' type='text' value='{$option}' />";
    }
    
    function display_setting_author_checkbox($args)
    {
        $file_code = $args[0];
        $authors = get_option('creators_feed_reader_features');
        
        $checked = FALSE;
        if(isset($authors[$file_code]) && $authors[$file_code] == 'on')
            $checked = TRUE;
        
        echo "<input id='creators_feed_reader_features[{$file_code}]' type='checkbox' name='creators_feed_reader_features[{$file_code}]' ".($checked?"checked='checked'":"")." />";
    }
    
    function display_options_page()
    {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
        
        echo '<div class="wrap">';
	echo '<h2>Creators RSS Feed Options</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields('creators_rss');
        do_settings_sections('creators_rss');
        echo '<p class="submit"><input type="submit" value="Save Changes" class="button button-primary" id="submit" name="submit"></p>';
        echo '</form>';
	echo '</div>';
    }
    
    function settings_load_hook()
    {
        if(isset($_GET['settings-updated']) && $_GET['settings-updated'])
        {
            $features = get_option('creators_feed_reader_features');
            $ids = get_option('creators_feed_reader_user_ids');
            
            foreach($features as $file_code=>$v)
            {
                if($v && !isset($ids[$file_code]))
                {
                    self::$instance->create_user($file_code);
                }
            }
        }
    }
    
    function plugin_action_links($links)
    {
        $links[] = '<a href="'. esc_url( get_admin_url(null, 'options-general.php?page=creators-rss-feeds-options') ) .'">Settings</a>';
        return $links;
    }
}

/*
 * Set hooks
 */

add_action('plugins_loaded', array('CreatorsRSSParser', 'init'));

// Activation hook
register_activation_hook( __FILE__, array('CreatorsRSSParser', 'activate'));

// Deactivation hook
register_deactivation_hook( __FILE__, array('CreatorsRSSParser', 'deactivate'));

// Uninstall hook
register_uninstall_hook( __FILE__, array('CreatorsRSSParser', 'uninstall'));

/* End of file */