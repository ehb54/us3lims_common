<div id=_submitprogress class='color_c_darkblue_s_background_c_white_s_font_d_family_c_monospace_s_font_d_size_c_20px_s_text_d_align_c_center'></div>
<script>
us_submit_prog     = {};
us_submit_prog.msg = {};
us_submit_prog.ele = document.getElementById("_submitprogress");
us_submit_prog.update = function( msg ) {
    us_submit_prog.ele.innerHTML=msg;
}
us_submit_prog.append = function( msg ) {
    us_submit_prog.ele.innerHTML += "<br>" + msg;
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
