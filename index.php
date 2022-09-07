<?php
use Gitlab\Client;

include __DIR__ . "/vendor/autoload.php";

$config = require __DIR__ . '/config/config.php';
$cfgRedmine = $config['redmine'];
$cfgGitLab = $config['gitlab'];
$cfgEmail = $config['email'];
$listUser = $config['user'];
$dateNow = new \DateTime('now');

$arrReport = [];


// Get data from Redmine
if ($cfgRedmine['url'] && $cfgRedmine['key']) {
    foreach ($listUser as $user) {

        if (is_int((int)$user['redmine_id'])) {

            $xmlURLRedmine = $cfgRedmine['url'] . 'activity.atom'. "?key=" . $cfgRedmine['key'] . "&user_id=" . $user['redmine_id'];
            $xmlRedmine = file_get_contents($xmlURLRedmine);
            if($xmlRedmine){
                $movies = new \SimpleXMLElement($xmlRedmine);
                if($movies->entry){
                    foreach ($movies->entry as $entry) {
                        $dateTask = new \DateTime($entry->updated);
                        $dateDiff = $dateTask->diff($dateNow);
                        $arrEntry = (array)$entry;
                        $email = (string)$arrEntry['author']->email;

                        if ($dateTask->format('Y-m-d') == $dateNow->format('Y-m-d')) {
                            $arrReport[$email]['redmine']['task'][] = $arrEntry;
                        }

                        $arrReport[$email]['name'] = preg_replace('/^Vitamin\s\(new\)\:\s/i', '', (string)$movies->title);
                    }
                }else{

                    // If the user has no tasks for a long time
                    $opts = array(
                        'http'=>array(
                            'method'=>"GET",
                            'header'=>"X-Redmine-API-Key: " . $cfgRedmine['api']
                        )
                    );

                    $context = stream_context_create($opts);
                    $xmlRedmine = file_get_contents($cfgRedmine['url'] . '/users/' . $user['redmine_id'] . '.xml',false, $context);

                    $movies = new \SimpleXMLElement($xmlRedmine);

                    $email = (string)$movies->mail;
                    $arrReport[$email]['name'] = $movies->firstname . ' ' . $movies->lastname;
                }
            }
        }
    }
}

// Get data from Gitlab
$objClient = new \Gitlab\Client($cfgGitLab['url']);
$objClient->authenticate($cfgGitLab['key']);
$objClient->setOption('timeout', 30000);

//Список проектов
$arrProjects = $objClient->api('projects')->all(1, 9999);
$arrProjectsActive = [];
foreach ($arrProjects as $project) {
    $dateUpdate = new \DateTime($project['last_activity_at']);

    if ($dateUpdate->format('Y-m-d') === $dateNow->format('Y-m-d')) {
        $arrProjectsActive[] = $project;
    }
}

// Users list
$arrUsersEmail = [];
$arrGetUsers = $objClient->api('users')->all();
foreach ($arrGetUsers as $arrUser) {
    if ($arrUser['state'] !== 'blocked') {
        $arrUsersEmail[$arrUser['id']] = $arrUser['email'];
    }
}

// Project commits list
foreach ($arrProjectsActive as $project) {
    $events = $objClient->api('projects')->events($project['id'], 0, 20);

    foreach ($events as $event) {
        $dateEvent = new \DateTime($event['created_at']);

        if ($dateEvent->format('Y-m-d') === $dateNow->format('Y-m-d')) {
            $tempData['project_name'] = $project["name"];

            if (!empty($arrUsersEmail[$event['author_id']])) {
                $email = $arrUsersEmail[$event['author_id']];
                if (!empty($arrReport[$email])) {
                    $arrReport[$email]['gitlab']['events'][] = $event;
                }
            }
        }
    }
}

// Generate context for PDF
$html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
</head>
<body>';

foreach ($arrReport as $key => $user) {

    $html .= '<h1 style="margin-top: 10%">' . $user['name'] . '</h1>';

    $html .= '<h2>Redmine</h2>';
    if (!empty($user['redmine'])) {
        if (count($user['redmine']['task']) > 0) {
            foreach ($user['redmine']['task'] as $task) {
                $date = new DateTime($task['updated']);
                $link = (array) $task["link"];
                $html .= '<p>';
                $html .= $date->modify('+5 hour')->format('d.m.Y H:i');
                $html .= ' <a href="' . preg_replace('/redmine-backend\.scarlett:3000/i','redmine.vigroup.ru',$link['@attributes']['href']) . '">' . $task['title'];
                $html .= '</a></p>';
            }
        }
    } else {
        $html .= '<p>Пусто</p>';
    }


    $html .= '<h2>GitLab</h2>';
    if (!empty($user['gitlab'])) {
        if (count($user['gitlab']['events']) > 0) {
            foreach ($user['gitlab']['events'] as $event) {
                if ($event['data'] != null) {
                    $data = $event['data'];
                    $dateEvent = new \DateTime($event['created_at']);

                    $html .= '<p>';
                    $html .= $dateEvent->modify('+5 hour')->format('d.m.Y H:i');
                    $html .= ' <a href="' . $data['repository']['homepage'] . '">' . $data['repository']['name'] . '</a></p>';

                    foreach ($data['commits'] as $commit) {
                        $html .= '<p><a href="' . $commit['url'] . '">' . $commit['message'] . '</a></p>';
                    }
                }
            }
        }
    } else {
        $html .= '<p>Пусто</p>';
    }

}

$html .= '
</body>
</html>';


// Save file
$filePath = '/var/www/report.pdf';
$mpdf = new mPDF('');
$mpdf->mb_enc = 'UTF-8';
$mpdf->WriteHTML($html);
$mpdf->Output($filePath, 'F');

// Send email
$mail = new PHPMailer;

$mail->isSMTP();
$mail->Host = $cfgEmail['host'];
$mail->SMTPAuth = true;
$mail->Username = $cfgEmail['from'];
$mail->Password = $cfgEmail['pass'];
$mail->SMTPSecure = 'tls';
$mail->Port = 587;

$mail->setFrom($cfgEmail['from'], 'Report');
foreach($cfgEmail['to'] as $email){
    $mail->addAddress($email);
}


$mail->addAttachment($filePath);
$mail->isHTML(true);

$mail->Subject = 'Report GitLab Redmine';
$mail->Body    = ' ';

if(!$mail->send()) {
    echo 'Message could not be sent.';
    echo 'Mailer Error: ' . $mail->ErrorInfo;
} else {
    if(is_file($filePath)){
        unlink($filePath);
    }
}