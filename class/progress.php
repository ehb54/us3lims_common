<div id=_submitprogress style="color:darkblue;background:white;font-family:monospace;font-size:20px;text-align:center"></div>
<script>
us_submit_prog     = {};
us_submit_prog.msg = {};
us_submit_prog.ele = document.getElementById("_submitprogress");
us_submit_prog.update = function( msg ) {
    us_submit_prog.ele.innerHTML=msg;
}
us_submit_prog.show = function() {
    us_submit_prog.ele.style.display = "block";
}
us_submit_prog.hide = function() {
    us_submit_prog.ele.style.display = "none";
}
us_submit_prog.msg.prep = function( x ) {
    us_submit_prog.update( `preparing datasets - ${x} remaining` );
}
us_submit_prog.msg.submit = function( x ) {
    us_submit_prog.update( `submitting ${x}` );
}
us_submit_prog.hide();
</script>
