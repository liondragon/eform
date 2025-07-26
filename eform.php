<?php
/*
  Plugin Name: Enhanced iContact Form
  Plugin URI: https://inspiredexperts.com
  Description: Advanced Internal Contact Form with improved security
  Version: 1.5
  Author: James Alexander
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
// Set Debug Level
define('DEBUG_LEVEL', 2);
// Hook into PHPMailer for more detailed error information
add_action('phpmailer_init', 'handle_phpmailer_init');
function handle_phpmailer_init($phpmailer) {
    if (defined('DEBUG_LEVEL') && DEBUG_LEVEL === 3) {
        $phpmailer->SMTPDebug = 2; // Adjust the level based on your need
        $phpmailer->Debugoutput = function($str, $level) {
            enhanced_icf_log('PHPMailer Error', ['level' => $level, 'message' => $str]);
        };

        // Example: Catching PHPMailer exceptions (if thrown)
        try {
            // Any additional PHPMailer setup can go here
        } catch (Exception $e) {
            enhanced_icf_log('PHPMailer Exception', ['message' => $e->getMessage()]);
        }
    }
}

function enhanced_icf_enqueue_scripts() {
    // Use plugins_url() to get the correct URL to your JS file
    $js_url = plugins_url('enhanced-form.js', __FILE__);

    // Enqueue the script
    wp_enqueue_script('enhanced-icf-js', $js_url, array(), '1.0', true);
}
add_action('wp_enqueue_scripts', 'enhanced_icf_enqueue_scripts');

// Set up a centralized logging function
function enhanced_icf_log($message, $context = array(), $form_data = null) {
	$log_file = WP_CONTENT_DIR . '/forms.log';
	global $global_ipaddress; // Declare the global variable within the function
    if (DEBUG_LEVEL == 2 && $form_data !== null) {
        // Include form data in the log
        $context['form_data'] = $form_data;
    }
    // Automatically fetch the IP address using the function
    $context['ip'] = filter_var($global_ipaddress, FILTER_VALIDATE_IP) ?: 'UNKNOWN';

    // Set the source
    $context['source'] = 'Enhanced iContact Form';

    // Add the message to the context
    $context['message'] = $message;

    // Add the user agent if it's not already set
	$context['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

	// Add the HTTP referrer to the context if it exists
	$context['referrer'] = isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field($_SERVER['HTTP_REFERER']) : 'No referrer';

    // Convert the context (now containing all information) to JSON
    $jsonLogEntry = json_encode($context, JSON_PRETTY_PRINT);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Fallback for JSON encoding errors
        $jsonLogEntry = 'Error in JSON encoding of log entry: ' . json_last_error_msg();
    }
	
    // Check if the custom log file path is set and valid
    if (!empty($log_file) && is_writable(dirname($log_file))) {
        // Log to the custom log file
        error_log($jsonLogEntry . "\n", 3, $log_file);
    } else {
        // Fallback to the default error log
        error_log($jsonLogEntry);
    }
}

// Function to get client IP address
function enhanced_icf_get_the_ip() {
    // List of headers that are commonly used to pass the client IP address
    // Ordered from the most reliable to less reliable
    $headers_to_check = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare header, if you're using Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP'
    ];

    foreach ($headers_to_check as $key) {
		// Check if the server variable is set and not empty
        if (!empty($_SERVER[$key])) {
            // Split the header into parts (in case it's a list of IPs)
            $ipList = array_map('trim', explode(',', $_SERVER[$key]));
            
            // Validate each IP in the list
            foreach ($ipList as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip; // Return the first valid IP
                }
            }
        }
    }

    // Fallback to REMOTE_ADDR, which is the direct IP address of the requester
    return filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ?: 'UNKNOWN';
}
$global_ipaddress = enhanced_icf_get_the_ip();

class Enhanced_Internal_Contact_Form {
    private $form_errors = array();
	private $ipaddress;
	
	private function get_email_subject($template, $data) {
    if (isset($this->template_configurations[$template]['email_subject'])) {
        $subjectTemplate = $this->template_configurations[$template]['email_subject'];
        // Replace placeholders in the subject template with actual data
        $subject = str_replace('{name}', $data['name'], $subjectTemplate);
        // Add more replacements as needed
        return $subject;
    }

    return 'Default Subject'; // Fallback subject line
}
	
	private $template_configurations = [
		'default' => [
			'email_subject' => 'Quote Request - {name}'
		],
		'custom' => [
			'email_subject' => 'Contact Form'
		]
	];
	//NEW TEMPLATE CODE START
	private function get_template_configurations() {
		return [
			'default' => [
				'fields' => [
					'name_input' => [
						'type' => 'text', 
						'placeholder' => 'Your Name', 
						'required' => '',
						'autocomplete' => 'name',
						'aria-label' => 'Your Name',
						'aria-required' => 'true',
						'style' => 'grid-area: name'
					],
					'email_input' => [
						'type' => 'email', 
						'placeholder' => 'Your Email', 
						'required' => '',
						'autocomplete' => 'email',
						'aria-label' => 'email',
						'aria-required' => 'true',
						'style' => 'grid-area: email'
					],
					'tel_input' => [
						'type' => 'tel', 
						'placeholder' => 'Phone', 
						'required' => '',
						'autocomplete' => 'tel',
						'aria-label' => 'Phone',
						'aria-required' => 'true',
						'style' => 'grid-area: phone'
					],
					'zip_input' => [
						'type' => 'text', 
						'placeholder' => 'Project Zip Code', 
						'required' => '',
						'autocomplete' => 'postal-code',
						'aria-label' => 'Project Zip Code',
						'aria-required' => 'true',
						'style' => 'grid-area: zip'
					],
					'message_input' => [
						'type' => 'textarea', 
						'cols' => '21', 
						'rows' => '5', 
						'placeholder' => 'Please describe your project and let us know if there is any urgency',
						'required' => '',
						'aria-label' => 'Message',
						'aria-required' => 'true',
						'style' => 'grid-area: message'
					]
				],
				// Other settings for 'default' template can be added here
			],
			'custom' => [
				'fields' => [
					'message_input' => [
						'type' => 'textarea', 
						'placeholder' => 'And continue here ...', 
						'required' => '',
						'aria-label' => 'Message',
						'aria-required' => 'true'
					],
					'email_input' => [
						'type' => 'email', 
						'placeholder' => 'Enter Your eMail*', 
						'required' => '',
						'autocomplete' => 'email'
					]
				],
				// Other settings for 'custom' template can be added here
			],
			// Add more templates as needed
		];
	}

	private function render_form_fields($template) {
		$config = $this->get_template_configurations();
		$fields = $config[$template]['fields'] ?? [];

		$form_fields_html = '';
		foreach ($fields as $field_name => $attributes) {
			// Dynamically create form elements based on attributes
			$form_fields_html .= $this->generate_field_html($field_name, $attributes);
		}

		return $form_fields_html;
	}

	private function generate_field_html($field_name, $attributes) {
		$type = $attributes['type'] ?? 'text'; // Default to 'text' if type not set
		$field_html = '';

		// Handle textarea separately as it's not a self-closing tag
		if ($type === 'textarea') {
			$field_html = "<textarea name='$field_name'";
			foreach ($attributes as $attr => $value) {
				if ($attr != 'type') { // Exclude 'type' attribute for textarea
					$field_html .= " $attr='$value'";
				}
			}
			$field_html .= "></textarea>";
		} else {
			$field_html = "<input type='$type' name='$field_name'";
			foreach ($attributes as $attr => $value) {
				$field_html .= " $attr='$value'";
			}
			$field_html .= " />";
		}

		return $field_html;
}
	//NEW TEMPLATE CODE END

    function __construct() {
        //add_shortcode('enhanced_icf_shortcode', array($this, 'render_form'));
		add_shortcode('enhanced_icf_shortcode', array($this, 'handle_shortcode'));
    }
	// Handle the shortcode with an optional template parameter
    public function handle_shortcode($atts) {
    $atts = shortcode_atts(array('template' => 'default'), $atts);

    $template = $atts['template'];
    $response = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check which form submit button is present in POST data
        if (isset($_POST['enhanced_form_submit_' . $template])) {
            enhanced_icf_log('Form submitted', array('template' => $template));
            $response = $this->process_form_submission($template);
        }
    }
    $form_html = method_exists($this, "render_form_{$template}") ? $this->{"render_form_{$template}"}($template) : $this->render_form_html($template);
    
    return $response . $form_html;
}
	//Fetch Required Fields from a Template
	private function get_required_fields_for_template($template) {
		$config = $this->get_template_configurations();
		$fields = $config[$template]['fields'] ?? [];
		$requiredFields = [];

		foreach ($fields as $field_name => $attributes) {
			if ($attributes['required']) {
				$requiredFields[] = $field_name;
			}
		}

		return $requiredFields;
	}
	
	// Validation Functions
    private function validate_form($template) {
    // Retrieve the required fields for the specific template
    $requiredFields = $this->get_required_fields_for_template($template);
    $errors = [];

    // Check for empty required fields and validate all submitted fields
    foreach ($requiredFields as $field) {
        // Check if the required field is empty
        if (empty($_POST[$field])) {
			// Adding ucfirst and str_replace to format the field name for the error message
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.'; // Formatting the error message
        } else {
            // Validate the field if it's filled in
            $error = $this->validate_field($field, $_POST[$field]);
            if ($error) {
                $errors[] = $error;
            }
        }
    }

    // Validate other fields (like email, name, zip) if they are present in $_POST but not required
    foreach ($_POST as $field => $value) {
        if (!in_array($field, $requiredFields) && !empty($value)) {
            $error = $this->validate_field($field, $value);
            if ($error) {
                $errors[] = $error;
            }
        }
    }

    return $errors;
}

private function validate_field($field, $value) {
    switch ($field) {
        case 'name_input':
            if (strlen($value) < 4) {
                return 'Name should be at least 4 characters long.';
            }
            break;
        case 'email_input':
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return 'Invalid email format.';
            }
            break;
		case 'zip_input':
            if (!preg_match('/^\d{5}$/', $value)) {
                return 'Invalid Zip Code. Please enter a valid 5-digit Zip Code.';
            }
            break;
		case 'message_input':
            if (strlen($value) < 10) {
                return 'Message should be at least 10 characters long.';
            }
            break;
        // ... other cases ...
        default:
            // Optional: Handle unknown fields
    }
    return null;
}


    private function send_email($data, $template = 'default') {
		global $global_ipaddress; // Ensure access to the global variable
		// Check if there are no form errors
		if (count($this->form_errors) < 1) {
			// Determine the formatting function based on the template
			$format_function = "format_{$template}_email";
			if (method_exists($this, $format_function)) {
				// Call the template-specific function if it exists
				$visitor_message = $this->$format_function($data);
			} else {
				// Fallback to default email format if the specific template method does not exist
				$visitor_message = $this->format_default_email($data);
			}
        // Email setup
        $business_email = get_option('admin_email');
        $noreply_email = 'noreply@flooringartists.com';
        $headers = "From: {$data['name']} <$noreply_email>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\n";
        $headers .= "Content-Transfer-Encoding: 8bit\n";
        $headers .= "Reply-To: {$data['name']} <{$data['email']}>\r\n";
		$subject = $this->get_email_subject($template, $data);
		//$subject = $this->template_configurations[$template]['email_subject'] ?? 'Default Subject';
		// $subject = 'Quote Request - ' . $data['name'];

        // Sending the email
		$email_sent = wp_mail($business_email, $subject, $visitor_message, $headers);
        if ($email_sent) {
            // Success: Redirect to Thank You page.
            $url = "https://flooringartists.com/your-message-was-sent/";
            echo '<meta http-equiv="refresh" content="0;URL=\'' . $url . '\'" /> ';
            exit();
        } else {
			// Failure: Log the error with detailed information
			enhanced_icf_log('Error sending email', [
				'ip' => $global_ipaddress,
				'type' => 'Email Sending Failure',
				'details' => [
					'to' => $business_email,
					'subject' => $subject,
					'headers' => $headers,
					'visitor_message' => $visitor_message
				]
			]);
			// Additional SMTP error logging (only when DEBUG_LEVEL is 3)
			global $phpmailer;
			if (defined('DEBUG_LEVEL') && DEBUG_LEVEL === 3 && isset($phpmailer) && $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer) {
				$smtpErrorMsg = $phpmailer->ErrorInfo;
				enhanced_icf_log('Detailed SMTP Error', ['error' => $smtpErrorMsg]);
			}
			// User-friendly error message
			return '<div class="form-message">There was an error sending your message. Please try again later or contact us directly.</div>';
		}
    }
    }
	
	private function format_default_email($data) {
	// Extract data from the $data array
    $email = esc_html($data['email']);
    $phone = esc_html($data['phone']);
    $zip = esc_html($data['zip']);
    $message = nl2br(esc_html($data['message'])); // Convert new lines to <br> tags for HTML email
    // Format the email content for the default template
    $formatted_content = '<table width="500"><tr><td><b>Name:</b> </td><td>' . esc_html($data['name']) . '</td></tr>'
        . '<tr><td><b>Zip Code:</b> </td><td>' . $zip . '</td></tr>'
        . '<tr><td><b>Phone:</b> </td><td>' . $phone . '</td></tr>'
        . '<tr><td><b>Email:</b> </td><td>' . $email . '</td></tr>'
        . '<tr><td valign="top"><b>Message:</b> </td><td>' . $message . '</td></tr></table>';
    return $formatted_content;
	}
	private function format_custom_email($data) {
	// Extract data from the $data array
    $email = esc_html($data['email']);
    $message = nl2br(esc_html($data['message'])); // Convert new lines to <br> tags for HTML email
	$formatted_content = '<table width="500">'
        . '<tr><td><b>Email:</b> </td><td>' . $email . '</td></tr>'
        . '<tr><td valign="top"><b>Message:</b> </td><td>' . $message . '</td></tr></table>';
    return $formatted_content;
	}

    private function process_form_submission($template) {		
		// Check if $_POST is empty
		if (empty($_POST)) {
		enhanced_icf_log('Form Left Empty');
        return '<div class="form-message">No data submitted.</div>';
		}
		
        // Nonce Field Check
		if (empty($_POST) || !isset($_POST['enhanced_icf_form_nonce']) || !wp_verify_nonce($_POST['enhanced_icf_form_nonce'], 'enhanced_icf_form_action')) {
			enhanced_icf_log('Nonce Failed');
            return '<div class="form-message">Invalid submission detected.</div>';
        }
		
		// Honeypot Check
		$honeypotField = $_POST['enhanced_url'] ?? '';
		if (!empty($honeypotField)) {
			// Log the attempt and return an error message
			enhanced_icf_log('Bot Alert: Honeypot Filled');
			return '<div class="form-message">Human test failed. Too many fields. Please call us if you receive this message in error.</div>';
		}
		// Time-Based Check
		$submit_time = $_POST['enhanced_form_time'] ?? 0;
		if (time() - intval($submit_time) < 5) {
			enhanced_icf_log('Bot Alert: Fast Submission');
			return '<div class="form-message">Human test failed. Too fast. Please call us if you receive this message in error.</div>';
		}

		// JavaScript Field Check
		$jsCheckField = $_POST['enhanced_js_check'] ?? '';
		if (empty($jsCheckField)) {
			enhanced_icf_log('Bot Alert: JS Check Missing');
			return '<div class="form-message">JavaScirpt needs to be enabled to use this form. Please call us if you receive this message in error.</div>';
		}
		
		// Initialize and sanitize data right at the start
		$data = array(
				'name' => isset($_POST['name_input']) ? sanitize_text_field($_POST['name_input']) : '',
				'email' => isset($_POST['email_input']) ? sanitize_email($_POST['email_input']) : '',
				'phone' => isset($_POST['tel_input']) ? sanitize_text_field($_POST['tel_input']) : '',
				'zip' => isset($_POST['zip_input']) ? sanitize_text_field($_POST['zip_input']) : '',
				'message' => isset($_POST['message_input']) ? sanitize_textarea_field($_POST['message_input']) : ''
			);
		
		// Validate the form data
		$form_errors = $this->validate_form($template);
		
        if (empty($form_errors)) {
						// Call to send_email and store its response
			$email_response = $this->send_email($data, $template);
			// Return the response from send_email
			return $email_response;
		} else {
			/// Filter $data to include only keys that are present in $_POST
			$submitted_data = $submitted_data = array_filter($data, function($value) {
				return !empty($value);
			});
			
			// Log the errors with form data
			enhanced_icf_log('Form validation errors', [
				'type' => 'Validation Error',
				'errors' => $form_errors, // Include specific validation errors
				'form_data' => $submitted_data // Log only submitted data
			]);
			return '<div class="form-message">' . implode('<br>', $form_errors) . '</div>';
		}

    }
	//Form Setup
	private function generate_form_setup_html($template) {
		$class_name = 'eform_' . esc_attr($template);
        $nonce_field = wp_nonce_field('enhanced_icf_form_action', 'enhanced_icf_form_nonce', true, false);
        $post_id = get_the_ID();
        $action_url = $post_id ? esc_url(get_permalink($post_id)) : esc_url(home_url('/'));
        $form_markup = '<form class="' . esc_attr($class_name) . '" aria-label="Enhanced Contact Form" action="' . $action_url . '" method="post">';
        $form_markup .= $nonce_field;
        // Spam Traps
        $form_markup .= '<input type="hidden" name="enhanced_form_time" aria-hidden="true" tabindex="-1" value="' . time() . '">';
        $form_markup .= '<input type="hidden" class="enhanced_js_check" data-enhanced-js-check name="enhanced_js_check" aria-hidden="true" tabindex="-1">';
        $form_markup .= '<div class="inputwrap net"><input class="form_field" type="text" name="enhanced_url" aria-label="URL" aria-hidden="true" tabindex="-1" placeholder="URL" value=""/></div>';
        return $form_markup;
    }
	// Default form template
    private function render_form_html($template) {
		$form_markup = $this->generate_form_setup_html($template);
		// Specific form fields here
		$form_markup .= $this->render_form_fields($template);
		$form_markup .= '<button type="submit" name="enhanced_form_submit_' . esc_attr($template) . '" aria-label="Send Request" value="Send Request" style="grid-area: button">Send Request</button>';
		$form_markup .= '</form>';
		return $form_markup;
}
	private function render_form_custom($template) {
        $form_markup = $this->generate_form_setup_html($template);
        $form_markup .= '<h3>Hello,</h3>';
		$form_markup .= '<div class="inputwrap"><textarea cols="40" rows="10" name="message_input" aria-label="Message" placeholder="And continue here ..."></textarea></div>';
		$form_markup .= '<div class="bottom-row">';
		$form_markup .= '<div class="email_wrapper"><input type="email" autocomplete="email" required aria-required="true" name="email_input" aria-label="Email" value="" size="40" placeholder="Enter Your eMail*"></div>';
		$form_markup .= '<input type="hidden" name="submitted" value="1">';
		$form_markup .= '<div class="submit_wrapper"><input type="submit" name="enhanced_form_submit_' . esc_attr($template) . '" aria-label="Send Request" value="Click to Send"></div>';
        $form_markup .= '</div>';
        $form_markup .= '</form>';
        return $form_markup;
    }
    public function render_form($template = 'default') {
        $response = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $response = $this->process_form_submission($template);
        }
		// Define the method name based on the template
		$form_method = "render_form_{$template}";

		// Check if the method exists and call it, else call render_form_html
		$form_html = method_exists($this, $form_method) ? $this->{$form_method}($template) : $this->render_form_html($template);

		return $response . $form_html;
    }
}

new Enhanced_Internal_Contact_Form();
