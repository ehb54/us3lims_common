/* main.js
 *
 * JavaScript routines available to all scripts
 *
 */

function construction()
{
  alert( "That page is under construction. Please check back later!" );

  return false;
}

// Function to open a new window to display a file 
function get_detail(file)
{
   window.open(file,
               "convert",
               "toobar=no,location=no,directories=no,status=no," +
               "scrollbars=yes,resizable=yes,copyhistory=no,"    +
               "width=480,height=640,title=Application Detail"   );
}

// Function to display a graphic file in a new window
function show_image(file)
{
   window.open("display_image.php?file=" + file,
               "convert",
               "toobar=no,location=no,directories=no,status=no," +
               "scrollbars=yes,resizable=yes,copyhistory=no,"    +
               "width=800,height=600,title=Image Detail"   );
}

// Function to toggle the display of a division
function toggleDisplay( whichDiv, anchorID, anchorContent )
{
  if ( document.getElementById )
  {
    // This is the way the standards work
    var div = document.getElementById(whichDiv);
    var style2 = document.getElementById(whichDiv).style;
    // alert("toggle: style2=" + style2.display + ";div=" + whichDiv );
    style2.display = style2.display ? "" : "block";

    var anchor = document.getElementById( anchorID );
    if ( style2.display == 'block' )
    {
      if ( document.all )
        anchor.innerHTML = "Hide " + anchorContent;
      else
        anchor.textContent = "Hide " + anchorContent;
    }

    else
    {
      if ( document.all )
        anchor.innerHTML = "Show " + anchorContent;
      else
        anchor.textContent = "Show " + anchorContent;
    }

  }

  else if (document.all)
  {
    // This is the way old msie versions work
    var style2 = document.all[whichDiv].style;
    style2.display = style2.display ? "" : "block";

    var content = document.all[anchorID];
  }

  else if (document.layers)
  {
    // This is the way nn4 works
    var style2 = document.layers[whichDiv].style;
    style2.display = style2.display ? "" : "block";
  }
}

const main_debug = 0;

main_debug && console.log( 'main.js loaded v17' );

document.addEventListener('change', function(event) {
    main_debug && console.log( 'change event' );
    if ( event.target ) {
        main_debug && console.dir( event.target );
        if ( event.target.classList.contains( 'onchange-form-submit') ) {        
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-form-submit' );
            event.target.form.submit();
            return false;
        } else if ( event.target.classList.contains( 'onchange-generate-oligomer-string' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-generate-oligomer-string' );
            generate_oligomer_string();
            return false;
        } else if ( event.target.classList.contains( 'onchange-showhide-value-3-4' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-showhide-value-3-4' );
            show_hide( event.target.value,3,4 );
            return false;
        } else if ( event.target.classList.contains( 'onchange-get-person' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-get-person' );
            get_person( event.target );
            return false;
        } else if ( event.target.classList.contains( 'onchange-get-project' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-get-project' );
            get_project( event.target );
            return false;
        } else if ( event.target.classList.contains( 'onchange-get-lab' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-get-lab' );
            get_lab( event.target );
            return false;
        } else if ( event.target.classList.contains( 'onchange-get-solute-count' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-get-solute_count' );
            get_solute_count( event.target );
            return false;
        } else if ( event.target.classList.contains( 'onchange-set-edit-mode' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-set-edit-mode' );
            set_edit_mode( event.target );
            return false;
        } else if ( event.target.classList.contains( 'onchange-select-project' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-select-project' );
            select_project( event.target );
            return false;
        } else if ( event.target.classList.contains( 'onchange-select-document' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-select-document' );
            select_document();
            return false;
        } else if ( event.target.classList.contains( 'onchange-browse-document' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-browse-document' );
            browse_document( event.target );
            return false;
        } else if ( event.target.classList.contains( 'onchange-select-class' ) ) {
            event.preventDefault();
            main_debug && console.log( 'change event target has class onchange-select-class' );
            select_class( event.target );
            return false;
        } else {
            main_debug && console.error( 'unknown or unsupported change event received, returning true' );
            main_debug && console.dir( event.target );
            return true;
        }
    }
});

document.addEventListener('click', function(event) {
    main_debug && console.log( 'click event' );
    if ( event.target ) {
        main_debug && console.dir( event.target );
        if ( event.target.classList.contains( 'onclick-print-version') ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-print-version' );
            print_version();
            return false;
        } else if ( event.target.classList.contains( 'onclick-reset-message' ) ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-reset-message' );
            reset_message();
            return false;
        } else if ( event.target.classList.contains( 'onclick-export-file' ) ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-export-file' );
            export_file();
            return false;
        } else if ( event.target.classList.contains( 'onclick-construction' ) ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-construction' );
            construction();
            return false;
        } else if ( event.target.classList.contains( 'onclick-selectAllCells' ) ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-selectAllCells' );
            selectAllCells();
            return false;
        } else if ( event.target.classList.contains( 'onclick-return-toggle-advanced') ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-return-toggle-advanced' );
            event.preventDefault();
            return toggle('advanced');
        } else if ( event.target.classList.contains( 'onclick-show-info-arg') ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-show-info-arg' );
            if ( !event.target.dataset.arg ) {
                console.error( "click event onclick-show-info-arg has no dataset.arg" );
                return false;
            }
            main_debug && console.dir( event.target.dataset.arg );
            show_info( event.target.dataset.arg );
            return false;
        } else if ( event.target.classList.contains( 'onclick-show-report-detail-arg') ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-show-report-detail-arg' );
            if ( !event.target.dataset.arg ) {
                console.error( "click event onclick-show-report-detail-arg has no dataset.arg" );
                return false;
            }
            main_debug && console.dir( event.target.dataset.arg );
            show_report_detail( event.target.dataset.arg );
            return false;
        } else if ( event.target.classList.contains( 'onclick-show-solution-detail-args') ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-show-solution-detail-args' );
            if ( !event.target.dataset.args ) {
                console.error( "click event onclick-show-solution-detail-arg has no dataset.args" );
                return false;
            }
            main_debug && console.dir( event.target.dataset.args );
            try {
                const args = JSON.parse( event.target.dataset.args );
                show_solution_detail( ...args );
                return false;
            } catch( error ) {
                if ( error instanceof SyntaxError ) {
                    console.error( 'click event onclick-show-solution-detail-args parsing dataset.args encountered invalid JSON:', error.message );
                } else {
                    console.error( 'click event onclick-show-solution-args parsing dataset.args encountered an error', error );
                }
                return false;
            }
        } else if ( event.target.classList.contains( 'onclick-window-location-arg') ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-window-location-arg' );
            if ( !event.target.dataset.arg ) {
                console.error( "click event onclick-window-location-arg has no dataset.arg" );
                return false;
            }
            main_debug && console.dir( event.target.dataset.arg );
            window.location=event.target.dataset.arg;
            return false;
        } else if ( event.target.classList.contains( 'onclick-hide-arg') ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-hide-arg' );
            if ( !event.target.dataset.arg ) {
                console.error( "click event onclick-hide-arg has no dataset.arg" );
                return false;
            }
            main_debug && console.dir( event.target.dataset.arg );
            hide( event.target.dataset.arg );
            return false;
        } else if ( event.target.classList.contains( 'onclick-show-ctl-arg') ) {
            event.preventDefault();
            main_debug && console.log( 'click event target has class onclick-show-ctl-arg' );
            if ( !event.target.dataset.arg ) {
                console.error( "click event onclick-show-ctl-arg has no dataset.arg" );
                return false;
            }
            main_debug && console.dir( event.target.dataset.arg );
            show_ctl( event.target.dataset.arg );
            return false;
        } else {
            main_debug && console.error( 'unknown or unsupported click event received, returning true' );
            main_debug && console.dir( event.target );
            return true;
        }
    }
});

document.addEventListener('submit', function(event) {
    main_debug && console.log( 'submit event' );
    if ( event.target ) {
        main_debug && console.dir( event.target );
        if ( event.target.classList.contains( 'onsubmit-return-validate-this') ) {
            event.preventDefault();
            main_debug && console.log( 'submit event target has class onsubmit-return-validate-this' );
            return validate( event.target );
        } else if ( event.target.classList.contains( 'onsubmit-return-validate-this-args') ) {
            event.preventDefault();
            main_debug && console.log( 'submit event target has class onsubmit-return-validate-this-args' );
            if ( !event.target.dataset.args ) {
                console.error( "submit event onsubmit-return-validate-this-args has no dataset.args" );
                return false;
            }
            main_debug && console.dir( event.target.dataset.args );
            try {
                const args = JSON.parse( event.target.dataset.args );
                validate( event.target, ...args );
                return false;
            } catch( error ) {
                if ( error instanceof SyntaxError ) {
                    console.error( 'submit event onsubmit-return-validate-this-args parsing dataset.args encountered invalid JSON:', error.message );
                } else {
                    console.error( 'submit event onsubmit-return-validate-this-args parsing dataset.args encountered an error', error );
                }
                return false;
            }
        } else if ( event.target.classList.contains( 'onsubmit-return-validate-solutes-args') ) {
            event.preventDefault();
            main_debug && console.log( 'submit event target has class onsubmit-return-validate-solutes-args' );
            if ( !event.target.dataset.args ) {
                console.error( "submit event onsubmit-return-validate-solutes-args has no dataset.args" );
                return false;
            }
            main_debug && console.dir( event.target.dataset.args );
            try {
                const args = JSON.parse( event.target.dataset.args );
                return validate_solutes( ...args );
            } catch( error ) {
                if ( error instanceof SyntaxError ) {
                    console.error( 'submit event onsubmit-return-validate-solutes-args parsing dataset.args encountered invalid JSON:', error.message );
                } else {
                    console.error( 'submit event onsubmit-return-validate-solutes-args parsing dataset.args encountered an error', error );
                }
                return false;
            }
        } else {
            main_debug && console.error( 'unknown or unsupported submit event received, returning true' );
            main_debug && console.dir( event.target );
            return true;
        }
    }
});
