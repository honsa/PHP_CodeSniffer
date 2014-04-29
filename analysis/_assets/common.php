<?php

function getRepoDirs($repo)
{
    $dirs = array();
    list($orgName, $repoName) = explode('/', $repo->url);

    $dirs['org']   = __DIR__."/../$orgName";
    $dirs['repo']  = $dirs['org']."/$repoName";
    $dirs['clone'] = $dirs['repo'].'/src';

    return $dirs;
}//end getRepoDirs()


function processRepo($repo, $checkoutDate, $runPHPCS=true, $runGit=true, $sniffs=array(), $resultFile=null)
{
    $dirs = getRepoDirs($repo);
    if (is_dir($dirs['org']) === false) {
        mkdir($dirs['org']);
    }

    if (is_dir($dirs['repo']) === false) {
        mkdir($dirs['repo']);
    }

    if ($resultFile === null) {
        $resultFile = $dirs['repo'].'/results.json';
    }

    $cloneDir   = $dirs['clone'];
    $cloneURL   = 'https://github.com/'.$repo->url.'.git';
    $prevTotals = null;

    echo "\tclone URL: $cloneURL".PHP_EOL;

    if (is_dir($cloneDir) === false) {
        if ($runGit === false) {
            echo "\t* respository has not been cloned, skipping *".PHP_EOL;
            return $resultFile;
        }

        // Clone it.
        echo "\t=> Cloning new repository".PHP_EOL;
        $cmd = "git clone --recursive $cloneURL $cloneDir";
        echo "\t\tcmd: $cmd".PHP_EOL;
        $output = shell_exec($cmd);
        echo implode(PHP_EOL, $output);
    } else if ($runPHPCS === true && file_exists($resultFile) === true) {
        // Load in old trend values.
        echo "\t=> Loading old trend values from $resultFile".PHP_EOL;
        $prevTotals = json_decode(file_get_contents($resultFile), true);
    }

    if ($runGit === true) {
        // Figure out the HEAD ref and use that.
        echo "\t=> Determining branch to use".PHP_EOL;
        $cmd = "cd $cloneDir; cat .git/refs/remotes/origin/HEAD";
        echo "\t\tcmd: ";
        echo str_replace('; ', PHP_EOL."\t\tcmd: ", $cmd).PHP_EOL;
        $branch = trim(shell_exec($cmd));
        echo "\t\tout: $branch".PHP_EOL;
        $branch = substr($branch, (strpos($branch, 'origin/') + 7));
        echo "\t\t* using branch $branch *".PHP_EOL;

        $cmd = "cd $cloneDir; ";
        if ($checkoutDate !== date('Y-m-d')) {
            echo "\t=> Checking out specific date: $checkoutDate".PHP_EOL;
            $cmd .= "git checkout `git rev-list -n 1 --before=\"$checkoutDate 00:00\" $branch` 2>&1; ";
        } else {
            echo "\t=> Updating repository".PHP_EOL;
            $cmd .= "git reset --hard; git clean -df; git checkout $branch 2>&1; git pull 2>&1; ";
        }

        $cmd .= 'git submodule update --init --recursive 2>&1';

        echo "\t\tcmd: ";
        echo str_replace('; ', PHP_EOL."\t\tcmd: ", $cmd).PHP_EOL;

        $output = trim(shell_exec($cmd));
        echo "\t\tout: ";
        echo str_replace(PHP_EOL, PHP_EOL."\t\tout: ", $output).PHP_EOL;
    } else {
        echo "\t* skipping respository update step *".PHP_EOL;
    }//end if

    if ($runPHPCS === true) {
        $checkDir          = $cloneDir.'/'.$repo->path;
        $infoReportPath    = __DIR__.'/PHPCSInfoReport.php';
        $summaryReportPath = __DIR__.'/PHPCSSummaryReport.php';
        $cmd  = 'phpcs -d memory_limit=256M '.$checkDir.' --standard='.__DIR__.'/ruleset.xml';
        $cmd .= ' --extensions=php,inc,'.$repo->extensions;
        $cmd .= ' --ignore=*/tests/*,'.$repo->ignore;
        $cmd .= ' --runtime-set project '.$repo->url;
        $cmd .= " --report=$summaryReportPath --report-$infoReportPath=$resultFile";

        if (empty($sniffs) === false) {
            $cmd .= ' --sniffs='.implode(',', $sniffs);
        }

        echo "\t=> Running PHP_CodeSniffer".PHP_EOL;
        echo "\t\tcmd: ";
        echo str_replace(' --', PHP_EOL."\t\tcmd: --", $cmd).PHP_EOL;
        $output = trim(shell_exec($cmd));
        echo "\t\tout: ";
        echo str_replace(PHP_EOL, PHP_EOL."\t\tout: ", $output).PHP_EOL;
    } else {
        echo "\t* skipping PHP_CodeSniffer step *".PHP_EOL.PHP_EOL;
        return $resultFile;
    }//end if

    if ($prevTotals !== null) {
        // Copy old trend data into the new result set.
        $newTotals = json_decode(file_get_contents($resultFile), true);
        foreach ($prevTotals['metrics'] as $metric => $data) {
            if (isset($data['trends']) === false) {
                continue;
            }

            if (isset($newTotals['metrics'][$metric]) === false) {
                $newTotals['metrics'][$metric] = array(
                                                  'total'       => 0,
                                                  'values'      => array(),
                                                  'percentages' => array(),
                                                  'trends'      => $data['trends'],
                                                 );
                continue;
            }

            foreach ($data['trends'] as $date => $values) {
                $newTotals['metrics'][$metric]['trends'][$date] = $values;
            }

            ksort($newTotals['metrics'][$metric]['trends']);
        }//end foreach

        file_put_contents($resultFile, jsonpp(json_encode($newTotals, JSON_FORCE_OBJECT)));
    }//end if

    return $resultFile;

}//end processRepo()


// Comparison function
function sortMetrics($a, $b) 
{
    if (empty($a['values']) === true) {
        return -1;
    } else if (empty($b['values']) === true) {
        return 1;
    }

    $aPercent = ($a['values'][$a['winner']] / $a['total']);
    $bPercent = ($b['values'][$b['winner']] / $b['total']);
    if ($aPercent < $bPercent) {
        return -1;
    } else {
        return 1;
    }

}//end sortMetrics()


// Comparison function
function sortRepos($a, $b) 
{
    return (strtolower($GLOBALS['repoList'][$a]) > strtolower($GLOBALS['repoList'][$b]));

}//end sortRepos()


/**
 * jsonpp - Pretty print JSON data
 *
 * In versions of PHP < 5.4.x, the json_encode() function does not yet provide a
 * pretty-print option. In lieu of forgoing the feature, an additional call can
 * be made to this function, passing in JSON text, and (optionally) a string to
 * be used for indentation.
 *
 * @param string $json  The JSON data, pre-encoded
 * @param string $istr  The indentation string
 *
 * @link https://github.com/ryanuber/projects/blob/master/PHP/JSON/jsonpp.php
 *
 * @return string
 */
function jsonpp($json, $istr='  ')
{
    $result = '';
    for($p=$q=$i=0; isset($json[$p]); $p++)
    {
        $json[$p] == '"' && ($p>0?$json[$p-1]:'') != '\\' && $q=!$q;
        if(!$q && strchr(" \t\n", $json[$p])){continue;}
        if(strchr('}]', $json[$p]) && !$q && $i--)
        {
            strchr('{[', $json[$p-1]) || $result .= "\n".str_repeat($istr, $i);
        }
        $result .= $json[$p];
        if(strchr(',{[', $json[$p]) && !$q)
        {
            $i += strchr('{[', $json[$p])===FALSE?0:1;
            strchr('}]', $json[$p+1]) || $result .= "\n".str_repeat($istr, $i);
        }
    }
    return $result;
}