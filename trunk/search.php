<?php
/*
 * search.php
 *
 * A place to display search results
 *
 */
include 'checkinstance.php';

include 'config.php';

// Start displaying page
echo<<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "https://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">

<html xmlns="https://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<!--
Description   : Website designed and implemented by Dan Zollars 
                and Borries Demeler, 2010

Copyright     : Copyright (c), 2011
                Bioinformatics Core Facility
                Department of Biochemistry
                UTHSCSA
                All Rights Reserved

Website       : http://bioinformatics.uthscsa.edu

Version       : beta

Released      : 6/30/2011

-->

<head>
  <meta http-equiv="content-type" content="text/html; charset=utf-8" />
  <title>Search Results -
         $org_name</title>
  <meta name="Author" content="$site_author" />
  <meta name="keywords" content="$site_keywords" />
  <meta name="description" content="$site_desc" />
  <meta name="robots" content="index, follow" />
  <meta name="verify-v1" content="+TIfXSnY08mlIGLtDJVkQxTV4kDYMoWu2GLfWLI7VBE=" />
  <link rel="shortcut icon" href="images/favicon.ico" />
  <link href="css/common.css" rel="stylesheet" type="text/css" />
  <script src="js/main.js" type="text/javascript"></script>

</head>

<body>

<!-- begin header -->
<div id="header" style='text-align:center;'>
   <table class='noborder'>
   <tr><td><img src='images/USLIMS3-banner.png' alt='USLims 3 banner' /></td>
   </tr>
   </table>
   <span style='font-size:20px;font-weight:bold;color:white;padding:0 1em;'>
    $org_name</span>

HTML;

include 'topmenu.php';

</div>

?>
