<?php
namespace Okta;

/**
 * Plugin Name: Okta Sign-In Widget
 * Plugin URI: https://github.com/oktadeveloper
 * Description: Log in to your site using the Okta Sign-In Widget
 * Version: 0.11.0
 * Author: Aaron Parecki, Tom Smith, Nico Triballier, Joël Franusic
 * Author URI: https://developer.okta.com/
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: okta
 * Domain Path: /languages
 */

class OktaSignIn
{

    public function __construct()
    {
        // This hook is run when the user first activates the plugin
        register_activation_hook( __FILE__, array( $this, 'activated' ) );

        // TODO: Refactor this after adding support
        //       for configuring this plugin via a settings page
        if(file_exists(plugin_dir_path(__FILE__) . "env.php")) {
          /*******************************/
          // Load environment variables
          include(plugin_dir_path(__FILE__) . "env.php");
          // [jpf] FIXME: Add support for configuring this plugin via a settings page:
          //              https://codex.wordpress.org/Creating_Options_Pages
          /*******************************/

          $this->env = array(
              'OKTA_BASE_URL' => OKTA_BASE_URL,
              'OKTA_WIDGET_CLIENT_ID' => OKTA_WIDGET_CLIENT_ID,
              'OKTA_CLIENT_ID' => OKTA_CLIENT_ID,
              'OKTA_CLIENT_SECRET' => OKTA_CLIENT_SECRET,
              'OKTA_AUTH_SERVER_ID' => (defined('OKTA_AUTH_SERVER_ID') ? OKTA_AUTH_SERVER_ID : false)
          );

          if($this->env['OKTA_AUTH_SERVER_ID']) {
                $this->base_url = sprintf(
                    '%s/oauth2/%s/v1',
                    $this->env['OKTA_BASE_URL'],
                    $this->env['OKTA_AUTH_SERVER_ID']
                );
          } else {
                $this->base_url = sprintf(
                    '%s/oauth2/v1',
                    $this->env['OKTA_BASE_URL']
                );
          }
        }

        // https://developer.wordpress.org/reference/hooks/login_init/
        add_action('login_init', array($this, 'loginAction'));

        // This runs on every pageload to insert content into the HTML <head> section
        // https://codex.wordpress.org/Plugin_API/Action_Reference/wp_head
        add_action('wp_head', array($this, 'addLogInExistingSessionAction'));


        add_action('init', array($this, 'startSessionAction'));
    }

    public function activated() {
      // TODO: remove this after adding support for configuring via settings page
      // Check for the existence of env.php
      if (!file_exists(plugin_dir_path(__FILE__) . "env.php")) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'Please copy env.example.php to env.php and fill in your Okta application details, then activate this plugin again.' );
      }
    }

    public function startSessionAction()
    {
        if (!session_id()) {
            session_start();
        }
    }

    public function addLogInExistingSessionAction()
    {
        if (!is_user_logged_in()) {
            $this->startSessionAction();
            $_SESSION['redirect_to'] = $_SERVER['REQUEST_URI'];
            include("templates/log-in-existing-session.php");
        }
    }

    private function httpPost($url, $body)
    {
        $args = array(
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => $body,
        );
        return wp_remote_post($url, $args);
    }

    public function loginAction()
    {
        // Support redirecting back to the page the user was on before they clicked log in
        $redirect_to = false;
        if (isset($_GET['redirect_to'])) {
            $redirect_to = $_GET['redirect_to'];
            $_SESSION['redirect_to'] = $_GET['redirect_to'];
        }

        // When signing out of WordPress, tell the Okta JS library to log out of Okta as well
        if (isset($_GET["action"]) && $_GET["action"] === "logout") {
            $this->logUserOutOfOkta();
        }

        if (isset($_GET['log_in_from_id_token'])) {
            $this->logUserIntoWordPressWithIDToken($_GET['log_in_from_id_token'], $redirect_to);
            exit;
        }

        if($this->useWordpressLogin()) {
            return;
        }

        // If there is no code in the query string, show the Okta sign-in widget
        $template = plugin_dir_path(__FILE__) . 'templates/sign-in-form.php';
        load_template($template);
        exit;
    }

    private function useWordpressLogin() {
        // TODO: add a setting to enable wordpress login

        if(isset($_GET['wordpress_login']) && $_GET['wordpress_login'] == 'true')
            return true;

        if(isset($_GET['action']) && $_GET['action'] == 'lostpassword')
            return true;

        if(isset($_GET['checkemail']))
            return true;

        if(isset($_POST['wp-submit']))
            return true;

        return false;
    }

    private function logUserOutOfOkta() {
        $user = wp_get_current_user();

        wp_clear_auth_cookie();

        $template = plugin_dir_path(__FILE__) . 'templates/sign-out.php';
        load_template($template);
        exit;
    }

    private function logUserIntoWordPressWithIDToken($id_token, $redirect_to)
    {
        /********************************************/
        // [jpf] TODO: Implement client-side id_token validation to speed up the verification process
        //             (~300ms for /introspect endpoint v. ~5ms for client-side validation)
        $payload = array(
            'client_id' => $this->env['OKTA_CLIENT_ID'],
            'client_secret' => $this->env['OKTA_CLIENT_SECRET'],
            'token' => $id_token,
            'token_type_hint' => 'id_token'
        );
        $response = $this->httpPost($this->base_url . '/introspect', $payload);
        if ($response === false) {
            die("Invalid id_token received from Okta");
        }
        $claims = json_decode($response['body'], true);
        if (!$claims['active']) {
            die("Okta reports that id_token is not active or client authentication failed:" . $claims['error_description']);
        }
        /********************************************/

        $this->logUserIntoWordPressFromEmail($claims['email'], $redirect_to);
    }

    private function logUserIntoWordPressFromEmail($email, $redirect_to)
    {
        // Find or create the WordPress user for this email address
        $user = get_user_by('email', $email);
        if (!$user) {
            $random_password = wp_generate_password($length = 64, $include_standard_special_chars = false);
            $user_id = wp_create_user($email, $random_password, $email);
            $user = get_user_by('id', $user_id);
        } else {
            $user_id = $user->ID;
        }

        // Actually log the user in now
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        error_log("Logging in WordPress user with ID of: " . $user_id);

        // See also: https://developer.wordpress.org/reference/functions/do_action/
        // Run the wp_login actions now that the user is logged in
        do_action('wp_login', $user->user_login, $user);

        if (isset($_SESSION['redirect_to'])) {
            $redirect_uri = $_SESSION['redirect_to'];
            unset($_SESSION['redirect_to']);
        } else {
            $redirect_uri = home_url();
        }
        wp_redirect($redirect_uri);
    }
}

$okta = new OktaSignIn();
