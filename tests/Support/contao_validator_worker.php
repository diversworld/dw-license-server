<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$profiles = json_decode(file_get_contents(dirname(__DIR__).'/Integration/contao-client.json'), true, flags: JSON_THROW_ON_ERROR);
$profile = $profiles[$input['profile']];
$path = dirname(__DIR__, 2).'/var/contao-integration/'.$input['profile'].'.php';
if (!hash_equals($profile['sha256'], hash_file('sha256', $path))) { throw new RuntimeException('Real client source was changed.'); }
require $path;
$validator = new \Diversworld\ContaoIssueServiceBundle\Application\License\LicenseValidationService($input['keys'], $input['tenant'], $input['domain']);
echo json_encode($validator->validate($input['token'], $input['now']), JSON_THROW_ON_ERROR);
