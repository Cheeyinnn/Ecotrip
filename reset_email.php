<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require "PHPMailer/src/Exception.php";
require "PHPMailer/src/PHPMailer.php";
require "PHPMailer/src/SMTP.php";

function sendResetEmail($email, $reset_link) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.mailtrap.io';
        $mail->SMTPAuth = true;
        $mail->Port = 2525;

        // REPLACE with your Mailtrap credentials
        $mail->Username = '5dd41c29aa2141';
        $mail->Password = '4d68efed9cf9d4';

        $mail->setFrom('no-reply@ecotrip.local', 'EcoTrip Support');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = "EcoTrip Password Reset";
        $mail->Body = "
            <p>You requested a password reset.</p>
            <p>Click below to reset your password:</p>
            <p><a href='$reset_link'>$reset_link</a></p>
            <p>This link expires in 1 hour.</p>
        ";

        $mail->send();

    } catch (Exception $e) {
        echo "Email failed: {$mail->ErrorInfo}";
    }
}
?>
