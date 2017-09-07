<?php
if (isset($_POST['Delete'])) {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  echo '<pre>';
  print_r('OUTPUT:');
  echo '</pre>';

  $fakeSubscriberEmails = array('john@mailsac.com', 'paul@mailsac.com');
  //$email = 'john@mailsac.com';
  $listId = '382de78e4c';

  foreach ($fakeSubscriberEmails as $email) {
    deleteMailchimpMember($email, $listId);
  }
}

function deleteMailchimpMember($email,$listId)
{
    $apiKey = 'e94add562694872e8855b1efa1a04029-us5';

    $memberId = md5(strtolower($email));

    $dataCenter = substr($apiKey, strpos($apiKey, '-') + 1);

    $url = 'https://' . $dataCenter . '.api.mailchimp.com/3.0/lists/' . $listId . '/members/' . $memberId;

    $json = json_encode
    (
        [
            'email_address' => $email
        ]
    );

    // echo '<pre>';
    // print_r($json);
    // echo '</pre>';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_USERPWD, 'user:' . $apiKey);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo '<pre>';
    print_r('email deleted');
    echo '</pre>';

    RETURN $httpCode;
}

?>

<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Delete Gray Mail</title>
  </head>
  <body>

    <form method="post">
      <input type="submit" name="Delete" value="Delete">
    </form>

  </body>
</html>

<!-- LIST ID: 382de78e4c -->

<!-- API Links: -->
<!-- http://52.163.93.69/delete-user-mailchimp-list-using-api-v3-php/ -->
<!-- https://support.qualityunit.com/266360-How-to-delete-Mailchimp-list-members-using-API-v3 -->
