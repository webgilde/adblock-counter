<?php
/*
  Plugin Name: Adblock Counter
  Version: 1.1.0
  Plugin URI: http://webgilde.com/
  Description: Count how many of your visitors are using an ad blocker.
  Author: Thomas Maier
  Author URI: http://www.webgilde.com/
  License: GPL v3

  adblock-counter Plugin for WordPress
  Copyright (C) 2013, Thomas Maier (thomas.maier@webgilde.com)

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * @todo use this method for specific blocks (of there is the same ad block on each page): http://pastebin.com/QdJEpR8K
 * 
 */

//avoid direct calls to this file
if (!function_exists('add_action')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit();
}

define('ABCOUNTERVERSION', '1.1.0');
define('ABCOUNTERNAME', 'adblock-counter');
define('ABCOUNTERTD', 'adblock-counter');
define('ABCOUNTERDIR', basename(dirname(__FILE__)));
define('ABCOUNTERPATH', plugin_dir_path(__FILE__));

if (!class_exists('ABCOUNTER_CLASS')) {

    class ABCOUNTER_CLASS {

        /**
         * initialize the plugin
         * @update 1.1
         */
        public function __construct() {

            // perform on plugin activation
            register_activation_hook(__FILE__, array($this, '_activation'));

            add_action('admin_menu', array($this, 'add_menu_page'));
            // load admin scripts
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

            // everything connected with the measuing - run only when active
            if ($this->_is_measuring()) {
                add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
                add_action('wp_footer', array($this, 'include_bannergif'));
                add_action('wp_footer', array($this, 'display_footer'));
                // ajax call for logged in and not logged in users
                add_action('wp_ajax_merged_count', array($this, 'count_merged_count'));
                add_action('wp_ajax_nopriv_merged_count', array($this, 'count_merged_count'));
            }
        }

        /**
         * add menu page in tools section
         */
        public function add_menu_page() {
            add_management_page(__('AdBlock Counter Dashboard', ABCOUNTERTD), __('AdBlock Counter', ABCOUNTERTD), 'manage_options', 'adblock-counter', array($this, 'render_menu_page'));
        }

        /**
         * render the menu page
         */
        public function render_menu_page() {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }

            if (!empty($_POST['abcounter'])) {
                // reset statistics
                if ($_POST['abcounter'] == 'reset') {
                    $this->_reset_statistics();
                }
                // start measuring
                if ($_POST['abcounter'] == 'start') {
                    $this->_start_measuring();
                }
                // stop measuring
                if ($_POST['abcounter'] == 'stop') {
                    $this->_stop_measuring();
                }
            }

            require_once 'templates/settings.php';
            require_once 'templates/statistics.php';
        }

        /**
         * add scripts for the frontend
         */
        public function enqueue_scripts() {
            // enqueue empty advertisement.js
            wp_register_script('adblock-counter-testjs', plugins_url('js/advertisement.js', __FILE__), array('jquery'), ABCOUNTERVERSION);
            wp_enqueue_script('adblock-counter-testjs');
            // add the ajax url for the frontend
            wp_localize_script('jquery', 'AbcAjax', array('ajaxurl' => admin_url('admin-ajax.php')));
        }

        /**
         * add scripts to admin panel
         */
        public function enqueue_admin_scripts() {
            wp_register_style('abc_admin_css', plugins_url('/css/admin-style.css', __FILE__), false, ABCOUNTERVERSION);
            wp_enqueue_style('abc_admin_css');
        }

        /**
         * display a img-tag with gif banner
         * @since 1.1
         */
        public function include_bannergif() {
            ?><img id = "abc_banner" src = "<?php echo plugins_url('/img/ads/banner.gif', __FILE__); ?>" alt = "banner" width = "1" height = "1" /><?php
        }

        /**
         * content box that goes into the footer
         * @update 1.1
         */
        public function display_footer() {
            ?><script>
                jQuery(document).ready(function($) {
                    setTimeout(function(){ // timeout to run after loading the advertisement.js
                        // count for missing js file
                        var nonce = '<?php echo get_option('abc_nonce'); ?>';
                        var data = {
                            action: 'merged_count'
                        };
                        var abc_blocked=false;
                        if ($.adblockJsFile === undefined){
                            data.abc_count_jsFile = true;
                            abc_blocked=true;
                        }
                                    
                        var banner = document.getElementById("abc_banner");                        
                                    
                        if (banner == null || banner.offsetHeight == 0){
                            data.abc_count_banner = true;
                            abc_blocked=true;
                        }else{
                            data.abc_count_banner = false;
                        }
            						
                        if(abc_blocked==true){	
                            AbcSetCookie('abc_adblock', 'enabled', 30);
                        }else{
                            AbcSetCookie('abc_adblock', 'disabled', 30);
                        }
                        data.abc_count_views=true;
                        data.abc_count_unique=true;
                        						
                        $.post(AbcAjax.ajaxurl, data, function(response) {
                            if ( !AbcGetCookie('AbcUniqueVisitorJsFile') || AbcGetCookie('AbcUniqueVisitorJsFile') != nonce  ) {
                                AbcSetCookie('AbcUniqueVisitorJsFile', nonce, 30);     
                            }     
                            if ( !AbcGetCookie('AbcUniqueVisitorBanner') || AbcGetCookie('AbcUniqueVisitorBanner') != nonce  ) {
                                AbcSetCookie('AbcUniqueVisitorBanner', nonce, 30);     
                            }     
                            if ( !AbcGetCookie('AbcUniqueVisitor') || AbcGetCookie('AbcUniqueVisitor') != nonce ) {
                                AbcSetCookie('AbcUniqueVisitor', nonce, 30);    
                            }
                        });			
                    },100);
                });
                function AbcGetCookie(c_name)
                {
                    var i,x,y,ARRcookies=document.cookie.split(";");
                    for (i=0;i<ARRcookies.length;i++)
                    {
                        x=ARRcookies[i].substr(0,ARRcookies[i].indexOf("="));
                        y=ARRcookies[i].substr(ARRcookies[i].indexOf("=")+1);
                        x=x.replace(/^\s+|\s+$/g,"");
                        if (x==c_name)
                        {
                            return unescape(y);
                        }
                    }
                }

                /**
                 * name = cookie name
                 * value = cookie value
                 * exdays = days until cookie expires
                 */
                function AbcSetCookie( name, value, exdays, path, domain, secure)
                {
                    var exdate=new Date();
                    exdate.setDate(exdate.getDate() + exdays);
                    document.cookie = name + "=" + escape(value) + 
                        ((exdate == null) ? "" : "; expires=" + exdate.toUTCString()) +
                        ((path == null) ? "; path=/" : "; path=" + path) +        
                        ((domain == null) ? "" : "; domain=" + domain) +
                        ((secure == null) ? "" : "; secure");
                }

            </script><?php
        }

        /**
         * count the total page views
         * @update 1.1
         */
        public function count_page_views() {

            // if (is_admin()) return;

            $page_views = get_option('abc_page_views', 0);
            $page_views++;
            update_option('abc_page_views', $page_views);

            //wp_die();
        }

        /**
         * count the total page views
         * use the nonce to identify the visitor
         * @update 1.1
         */
        public function count_unique_visitors() {

            // if (is_admin()) return;
            // return if the nonce did not change identical
            if (!empty($_COOKIE['AbcUniqueVisitor']) && $_COOKIE['AbcUniqueVisitor'] == get_option('abc_nonce'))
                return;

            $uniques = get_option('abc_unique_visitors', 0);
            $uniques++;
            update_option('abc_unique_visitors', $uniques);
        }

        /**
         * count when advertisement.js is missing
         * @update 1.1
         */
        public function count_jsFile() {

            $count = get_option('abc_page_views_jsFile', 0);
            $count++;
            update_option('abc_page_views_jsFile', $count);

            // only count, if wasn't count before or nonce was reset
            if (empty($_COOKIE['AbcUniqueVisitorJsFile']) || $_COOKIE['AbcUniqueVisitorJsFile'] != get_option('abc_nonce')) {

                $uniques = get_option('abc_unique_visitors_jsFile', 0);
                $uniques++;
                update_option('abc_unique_visitors_jsFile', $uniques);
            }
        }

        /**
         * count when banner is missing
         * @since 1.1
         */
        public function count_banner() {
            $count = get_option('abc_page_views_bannerFile', 0);
            $count++;
            update_option('abc_page_views_bannerFile', $count);

            // only count, if wasn't count before or nonce was reset
            if (empty($_COOKIE['AbcUniqueVisitorBanner']) || $_COOKIE['AbcUniqueVisitorBanner'] != get_option('abc_nonce')) {

                $uniques = get_option('abc_unique_visitors_bannerFile', 0);
                $uniques++;
                update_option('abc_unique_visitors_bannerFile', $uniques);
            }
        }

        /**
         * Should combine the total page views
         * @since 1.1
         */
        public function count_merged_count() {
            if (isset($_POST['abc_count_views']) && $_POST['abc_count_views'] == "true") {
                $this->count_page_views();
            }
            if (isset($_POST['abc_count_unique']) && $_POST['abc_count_unique'] == "true") {
                $this->count_unique_visitors();
            }
            if (isset($_POST['abc_count_jsFile']) && $_POST['abc_count_jsFile'] == "true") {
                $this->count_jsFile();
            }
            if (isset($_POST['abc_count_banner']) && $_POST['abc_count_banner'] == "true") {
                $this->count_banner();
            }
            wp_die();
        }

        /**
         * run on activation of the plugin
         */
        public function _activation() {

            $this->_update_nonce();
        }

        /**
         * check if the measuring is running
         * @return bool true if measuring, false if not
         */
        public function _is_measuring() {

            $start = get_option('abc_start');
            if (empty($start))
                return false;

            $stop = get_option('abc_stop');
            if (empty($stop))
                return true;

            $time = time();
            if ($stop < $time)
                return false;

            return false;
        }

        /**
         * start measuring
         */
        public function _start_measuring() {
            update_option('abc_start', time());
        }

        /**
         * stop measuring
         */
        public function _stop_measuring() {

            update_option('abc_stop', time());
        }

        /**
         * check if the measuring was stopped, but not reset yet
         * @return bool true if measuring stopped
         */
        public function _is_stopped() {

            $stop = get_option('abc_stop');
            if (empty($stop))
                return false;

            $time = time();
            if ($stop < $time)
                return true;

            return false;
        }

        /**
         * reset the statistics to 0
         */
        public function _reset_statistics() {

            update_option('abc_page_views', 0);
            update_option('abc_unique_visitors', 0);
            update_option('abc_page_views_jsFile', 0);
            update_option('abc_unique_visitors_jsFile', 0);
            update_option('abc_page_views_bannerFile', 0);
            update_option('abc_unique_visitors_bannerFile', 0);
            update_option('abc_start', 0);
            update_option('abc_stop', 0);

            $this->_update_nonce();
        }

        /**
         * update nonce using the current time
         * @return string $nonce nonce, if needed as a return
         */
        public function _update_nonce() {

            $nonce = wp_create_nonce(time());
            update_option('abc_nonce', $nonce);
            return $nonce;
        }

    }

    $adblock_counter = new ABCOUNTER_CLASS();
}
