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
        $mail->Username = 'b8bbcfb6e70940';
        $mail->Password = 'faa75c357b0d79';

        $mail->setFrom('no-reply@ecotrip.local', 'EcoTrip Support');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = "EcoTrip Password Reset";
        $mail->Body = "
            <p>You requested a password reset.</p>
            <p>Click below to reset your password:</p>
            <p><a href='$reset_link'>$reset_link</a></p>
            <p>This link expires in 5 minutes.</p>
        ";

        $mail->send();

    } catch (Exception $e) {
        echo "Email failed: {$mail->ErrorInfo}";
    }
}
?>
