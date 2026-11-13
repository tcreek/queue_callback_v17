<?php
/** Prevent releases omitting runtime views, helpers, AGIs or recordings. */
$root=dirname(__DIR__);
$xml=simplexml_load_file($root.'/module.xml');
if ($xml===false) {throw new RuntimeException('Invalid module manifest');}
$listed=[];
foreach ($xml->files->file as $file) {
    $path=(string)$file;
    if (!is_file($root.'/'.$path)) {throw new RuntimeException('Missing declared file: '.$path);}
    if (isset($listed[$path])) {throw new RuntimeException('Duplicate manifest entry: '.$path);}
    $listed[$path]=true;
}
foreach (['/*.php','/views/*.php','/agi-bin/*.agi','/announcements/*'] as $pattern) {
    foreach (glob($root.$pattern) as $file) {
        if (!is_file($file)) {continue;}
        $path=substr($file,strlen($root)+1);
        if (!isset($listed[$path])) {throw new RuntimeException('Runtime asset omitted from manifest: '.$path);}
    }
}
echo "PASS: manifest includes every runtime PHP file, AGI, view and packaged announcement; all declared files exist\n";
