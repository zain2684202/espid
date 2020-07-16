<?php
use PHPMailer\PHPMailer\PHPMailer;
require "phpmailer.php";

// All mailform settings
$to = "zain2684202@gmail.com";														// To Address
$from = "zain2684202@gmail.com";													// From Address
$fromName = "Your Name";											// From Name
$fromThem = ("0" == "1" ? true : false);					// Use Sender as From Address
$fromThemReplyTo = ("0" == "1" ? true : false);	// Use Sender as Reply-To
$fromNameThem = ("0" == "1" ? true : false);			// Use Sender Name as From Name
$fromNameThemField = "{name}";						// Name of the field(s) that can contain the Sender Name
$template = "Hi,<br><br>You have received a new message from your website.<br><br>{formdata}<br><br>Date: {date}<br>Remote IP: {ip}<br><br>---<br>Have a nice day.";											// Mail Template
$autorespondSubjectPrefix = "Re:";			// Autorespond Form Subject Prefix
$autorespondSubject = "";						// Autorespond Custom Subject
$autorespondTemplate = "Hi {name},<br><br>Thank you for your message. We'll get back to you as soon as we can.<br>Here's the information you sent us:<br><br>{formdata}<br><br>---<br>Have a nice day.";					// Autorespond Template
$rcp = ("0" == "3" ? true : false);						// Use reCAPTCHA (set to "3" if enabled, "0" if disabled)
$rcpScore = "0.5";									// reCAPTCHA Score
$rcpSecret = "";								// reCAPTCHA Secret Key


// We only do stuff if there's a POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
	try {
		// Sanitize user input
		SanitizeUserInput();

		// Set subject (and take it out of $_POST, so it doesn't end up in the body as well)
		$subject = $_POST["subject"];
		unset($_POST["subject"]);

		// Unset the 'disableButton' field, in case that too was posted
		unset($_POST["disableButton"]);

		// Determine if we need to autorespond
		$autorespond = (isset($_POST["autorespond"]) && $_POST["autorespond"] == "1" ? true : false);
		unset($_POST["autorespond"]);

		// Replace any \n breakline with a html breakline
		$_POST = str_replace("\n", "<br>", $_POST);

		// reCAPTCHA (only really does anything if reCAPTCHA is enabled)
		CheckReCAPTCHA();

		// Send mail (to-address, Sender as From Email or predefined From Email, Sender as From Name or predefined From Name, subject, template)
		if (SendPHPMail($to, ($fromThem ? $_POST["email"] : $from), ($fromThemReplyTo ? $_POST["email"] : null), SetFromName(), $subject, $template)) {

			// Send autorespond
			if ($autorespond) {
				$arSubject = $autorespondSubject ?: trim($autorespondSubjectPrefix . " " . $subject);
				if (! SendPHPMail($_POST["email"], $from, null, $fromName, $arSubject, $autorespondTemplate)) {
					json(false, "An error occured with sending autorespond e-mail");
				}
			}

			json(true);
		} else {
			json(false, "An error occured with sending e-mail");
		}
	}
	catch (Exception $e) {
		json(false, "An error occured");
	}
}


// Function to return JSON
function json($success, $msg="") {
	$arr = array("success" => $success);

	if ($msg)
		$arr["message"] = $msg;

	// Send the JSON and stop the presses
	header("Content-type: application/json");
	echo json_encode($arr);
	die();
}


// reCAPTCHA stuff
function CheckReCAPTCHA() {
	global $rcp, $rcpScore, $rcpSecret;

	if ($rcp) {
		if (!isset($_POST["g-recaptcha-response"])) {
			json(false, "POST did not contain g-recaptcha-response");
		}

		// Build POST request and execute it
		$rcpUrl = 'https://www.google.com/recaptcha/api/siteverify';
		$rcpResponse = $_POST["g-recaptcha-response"];
		unset($_POST["g-recaptcha-response"]);	// Remove this, as we don't want this to end up in the template ;)
		$rcpResponse = file_get_contents($rcpUrl . "?secret=" . $rcpSecret . "&response=" . $rcpResponse);
		$rcpResponse = json_decode($rcpResponse, true);

		// Check response
		if (!$rcpResponse["success"]) {
			json(false, "Invalid reCAPTCHA token");
		}

		// Check score
		if ($rcpResponse["score"] < intval($rcpScore)) {
			json(false, "Request did not pass reCAPTCHA");
		}
	}
}


// Render Template
function RenderTemplate($template) {
	// Use a copy of $_POST, so we don't pollute the original
	$POST = $_POST;

	// Extract all variables from the template
	preg_match_all("/\{([a-zA-Z0-9_-]+)\}/", $template, $matches);

	// Check what postvars don't exist in the template vars and put that in {formdata}
	$formdata = "";
	foreach ($POST as $k => $v) {
		if ( !in_array($k, $matches[1]) ) {
			// Implode array to make it look better
			if (is_array($v))
				$v = implode(", ", $v);

			// Replace some chars
			$k = str_replace("_", " ", $k);
			//$v = str_replace("\n", "<br>", $v);	<-- deze kan eruit nu, toch?

			// Add to formdata
			$formdata .= ($formdata ? "<br><br>" : "") . ucfirst($k) . ":<br>" . $v;
		}
	}
	$POST["formdata"] = $formdata;

	// Add some additional variables to the play
	$POST["ip"] = $_SERVER["REMOTE_ADDR"];
	$POST["date"] = date('Y-m-d H:i:s');

	// Loop through all variables of the template
	foreach($matches[1] as $val) {
		// Try to replace all variables in the template with the corresponding postvars (if they exist)
		$template = str_replace("{" . $val . "}", (isset($POST[$val]) ? $POST[$val] : ""), $template);
	}

	$template = "<html><body>" . $template . "</body></html>";
	return $template;
}


// Use PHPMailer to send the e-mail
function SendPHPMail($to, $from, $replyTo, $fromName, $subject, $template) {
	try {
		$mail = new PHPMailer(true);
		$mail->CharSet = "UTF-8";

		if ($replyTo)
			$mail->addReplyTo($replyTo);

		$mail->setFrom($from, $fromName);
		$mail->Subject = $subject;
		$mail->isHTML(true);
		$mail->Body = RenderTemplate($template);

		// Set 'to' address (there can be multiple e-mail addresses here, so we need to add all of them)
		$to = explode(",", $to);
		foreach ($to as $address) {
			$mail->addAddress(trim($address));
		}

		// Send the mail
		$mail->send();
		return true;
	}
	catch (Exception $e) {
		return false;
	}
}


// Set the From Name (this depends on a couple of factors, hence it gets its own function)
function SetFromName() {
	global $fromName, $fromNameThem, $fromNameThemField;

	$fn = "";

	// If we want to use the sender's name as the From Name, parse all name-{fields}
	if ($fromNameThem) {
		$fn = $fromNameThemField;

		// Extract variable names from the name field
		preg_match_all("/\{([a-zA-Z0-9_-]+)\}/", $fn, $nameMatches);

		// Loop through all variables of the name field
		foreach($nameMatches[1] as $val) {
			// Try to replace all variables with the corresponding postvars (if they exist, otherwise it'll make it empty)
			$fn = str_replace("{" . $val . "}", (isset($_POST[$val]) ? $_POST[$val] : ""), $fn);
		}
	}

	// Double check if fromName isn't empty, otherwise fill it with the predefined name
	$fn = trim($fn);
	$fn = ($fn ? $fn : $fromName);
	$fn = preg_replace('/(["“”‘’„”«»]|&quot;)/', "", $fn, -1);

	return $fn;
}


// Sanitize user input
function SanitizeUserInput() {
	$_POST = array_map("strip_tags", $_POST);
	$_POST = array_map("htmlspecialchars", $_POST);

	// Check if at least "subject" and "email" exist in the $_POST vars
	if ( !isset($_POST["subject"] ) || !isset($_POST["email"]) )
		json(false, "Not all required fields are present");

	// Check if a valid e-mail address is provided
	if ( !filter_var($_POST["email"], FILTER_VALIDATE_EMAIL) )
		json(false, "Invalid e-mail address");
}
?>