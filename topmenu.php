<?php

$use_site = preg_replace( '/\/uslims3_.*$/', '', $org_site );

echo<<<HTML
    <div id="globalnav">
       <ul class='level1'>
          <li><a target=_blank href="https://ultrascan.aucsolutions.com">Home</a></li>
          <li><a target=_blank href='https://ultrascan3.aucsolutions.com/software.php'>Downloads</a></li>
          <li><a target=_blank href='https://resources.aucsolutions.com/index.php'>Resources</a></li>
    
<!-- The first and third LIMS link should be custom and point to the local LIMS installation: -->

          <li class='submenu'><a href='https://$use_site/index.php'>LIMS</a>
          <ul class='level2'>
             <li><a target=_blank href='https://uslims.aucsolutions.com/lims_servers.php'>All LIMS Servers</a></li>
             <li><a href='https://$use_site/uslims3_newlims/request_new_instance.php'>Request New LIMS</a></li>
          </ul></li>
          <li><a target=_blank href='https://somo.aucsolutions.com/index.php'>SOMO</a></li>
          <li class='submenu'><a href='https://wiki.aucsolutions.com/'>Wiki</a>
          <ul class='level2'>
             <li><a target=_blank href='https://wiki.aucsolutions.com/ultrascan3/'>UltraScan-III</a></li>
             <li><a target=_blank href='https://wiki.aucsolutions.com/limsv3/'>LIMS-III</a></li>
             <li><a target=_blank href='https://wiki.aucsolutions.com/somo/'>US SOMO</a></li>
             <li><a target=_blank href='https://wiki.aucsolutions.com/openAUC/'>openAUC</a></li>
          </ul></li>
       </ul>
    </div>
HTML;
