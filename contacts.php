<?php
include 'checkinstance.php';

include 'header.php';
?>
<div id='content'>

	<h1 class="title">Local Facility Administrator Contact:</h1>
   <table cellpadding='10' border='1'>
   <tr valign='top'>
      <td><b>Facility Administrator:</b></td>
      <td><a href='mailto:<?php echo $admin_email; ?>'><?php echo $admin; ?></a><br/>
         <a href='mailto:<?php echo $admin_email; ?>'><?php echo $admin_email; ?></a><br/>
         Office/Telephone: <?php echo $admin_phone; ?><br/>
      </td>
   </tr>
   </table>
	<h1 class="title">UltraScan Contacts:</h1>

   <table cellpadding='10' border='1'>
   <tr valign='top'>
      <td><b>Project Leader:</b></td>
      <td><a href='mailto:borries.demeler@umontana.edu'>Borries
      	Demeler</a>, PhD<br/>
	      Professor<br/>
         <a href='mailto:borries.demeler@umontana.edu'>borries.demeler@umontana.edu</a><br/>
         Website: <a href='http://www.demeler.uthscsa.edu'>http://www.demeler.uthscsa.edu</a><br/>
         Telephone: 210-767-3332<br/>
         Fax: 403-329-2057<br/>
      </td>
   </tr>
   <tr valign='top'>
      <td><b>Website and Program Support:</b></td>
      <td><a href='mailto:gegorbet@gmail.com'>Gary Gorbet</a><br/>
          <a href='mailto:gegorbet@gmail.com'>gegorbet@gmail.com</a><br/>
      </td>
   </tr>
   <tr valign='top'>
      <td><b>Mailing Address:</b></td>
      <td>
         4401 University Drive <br/> 
	      <a href='http://www.uleth.ca'>Department of Chemistry and Biochemistry<br/>
         Mailcode 7760</a><br/>
	      <a href='http://www.uleth.ca'>The University of Lethbridge,  Alberta</a><br/>
	      Lethbridge, Alberta  T1K3M4<br/>
         USA
      </td>
   </tr>

   </table>
</div>

<p/>
<?php include 'footer.php'; ?>
