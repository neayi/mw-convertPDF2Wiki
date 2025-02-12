<?php

$mediawiki = '';

if (isset($_POST['markdown'])) {
    $markdown = $_POST['markdown'];

    $citations = extractCitations($markdown);
    $mediawiki = md2mediawiki($markdown);
    $mediawiki = replaceCitations($mediawiki, $citations);
}

function replaceCitations($mediawiki, $citations)
{
    $pattern = '@\[([0-9]+)\]@';
    preg_match_all($pattern, $mediawiki, $matches, PREG_SET_ORDER);

    $remainingCitations = array();

    foreach ($matches as $match) {
        $url = $citations[$match[1]];
        $number = $match[1];
        $count = 0;
        $mediawiki = preg_replace("/" . preg_quote($match[0]) . "/", "<ref name=\"ref_$number\">$url</ref>", $mediawiki, 1, $count);
        $mediawiki = preg_replace("/" . preg_quote($match[0]) . "/", "<ref name=\"ref_$number\" />", $mediawiki);

        if ($count == 0) {
            $remainingCitations[$number] = "\n* " . $url;
        }
    }

    $mediawiki .= "\n{{Pages liées}}\n\n== Références ==\n" . implode('', $remainingCitations) . "\n</references>";

    // Also replace those spans: <span id="conséquences-de-lhydromorphie"></span>
    $mediawiki = preg_replace('@<span id="([^"]+)"></span>@', '', $mediawiki);
    $mediawiki = preg_replace('@==\n[\n]+@', "==\n", $mediawiki);

    $mediawiki = "{{Facteur environnemental | Nom=\n| Image=\n}}\n" . $mediawiki;

    return $mediawiki;
}

function extractCitations(&$markdown)
{
    $citations = array();

    $pattern = '@^\[([0-9]+)\] ([^\n]+)@m';
    preg_match_all($pattern, $markdown, $matches, PREG_SET_ORDER);

    echo '<pre>';
    print_r($matches);

    foreach ($matches as $match) {
        $citations[$match[1]] = $match[2];
    }

    $markdown = preg_replace($pattern, '', $markdown);
    $markdown = preg_replace('@^Citations:@m', '', $markdown);
    $markdown = trim($markdown);

    return $citations;
}

function md2mediawiki($md)
{
    $proc = proc_open('pandoc -f markdown -t mediawiki', 
            array(0 => array('pipe', 'r'),
                1 => array('pipe', 'w'), 
                2 => array('pipe', 'w')), $pipes);

    fwrite($pipes[0], $md);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    
    proc_close($proc);
    
    return $stdout;
}

?>
<html>
<head>
    <title>MD2Wiki</title>
</head>
<body>
    <h1>MD2Wiki</h1>
    <form action="md2wiki.php" method="post">
        <textarea name="markdown" rows="40" cols="80"></textarea>
        <input type="submit" value="Convert">
    </form>

    <div style="border: 1px solid; width: 607px; padding: 10px;">
        <pre style="white-space: break-spaces;"><?php echo htmlentities($mediawiki); ?></pre>
    </div>
</body>
</html>