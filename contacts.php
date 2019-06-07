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
         <?php echo $admin_phone; ?><br/>
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
         Website: <a href='http://www.umontana.edu'>http://www.umontana.edu</a><br/>
         Telephone: 406-285-1935<br/>
      </td>
   </tr>
   <tr valign='top'>
      <td><b>Website and Program Support:</b></td>
      <td><a href='mailto:gegorbet@gmail.com'>Gary Gorbet</a><br/>
          <a href='mailto:gegorbet@gmail.com'>gegorbet@gmail.com</a><br/>
          832-466-9211 (<b>text only, please</b>)<br/>
      </td>
   </tr>
   <tr valign='top'>
      <td><b>Mailing Address:</b></td>
      <td>
         <a href='http://hs.umt.edu/chemistry'>Department of Chemistry<br/>
         <a href='http://www.umontana.edu'>The University of Montana</a><br/>
         32 Campus Drive<br/>
         Missoula, Montana  59812<br/>
         USA
      </td>
   </tr>

   </table>
</div>

<p/>
<?php include 'footer.php'; ?>
