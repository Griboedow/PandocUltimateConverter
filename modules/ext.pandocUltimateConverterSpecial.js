$(document).ready(function() {
  function uuidv4() {
    return "10000000-1000-4000-8000-100000000000".replace(/[018]/g, c =>
      (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
  }
  function getExtension(filename) {
    return filename.split('.').pop();
  }

  var page_name_MIN_LENGTH = 4;
  var page_name_MAX_LENGTH = 255;

  var page_name_field = $("#wpArticleTitle");
  var upload_file_field = $("#wpUploadFile");
  var hidden_file_name = $("#wpUploadedFileName");
  var submit_form = $("#mw-pandoc-upload-form");
  var submit_button = $("#mw-pandoc-upload-form-submit");

  var invalid_regex = /[\/\'\"\$]/g;
  if (submit_form.length == 1) {
    submit_form.on('submit', function (event) {
      event.preventDefault();

      var results;
      var page_name = page_name_field.val()

      // Validate page name
      if (page_name.length < page_name_MIN_LENGTH || page_name.length > page_name_MAX_LENGTH) {
        alert(mw.message('pandocultimateconverter-warning-page-name-length').text().replace("$1", page_name_MIN_LENGTH).replace("$2", page_name_MAX_LENGTH));
        return false;
      }

      if ((results = page_name.match(invalid_regex)) != null) {
        results = results.join(" ");
        alert(mw.message('pandocultimateconverter-warning-page-name-invalid-character').text().replace("$1", results));
        return false;
      }

      // Validate file selector
      if (!upload_file_field.val()){
        alert(mw.message('pandocultimateconverter-warning-file-not-selected').text())
      }

      // Upload image
      ext = getExtension(upload_file_field.val()).toLowerCase();
      file_name = 'pandocultimateconverter-' + uuidv4() + "." + ext;
      hidden_file_name.val(file_name);
      
      submit_form.append('<div style="" id="loadingDiv"><div class="loader">Loading...</div></div>');
      api = new mw.Api();
      var upload_file_params = {
        format: 'json',
        stash: false,
        ignorewarnings: 1,
        filename: file_name
      };  


      // TODO: add waiting form
      api.upload(upload_file_field[0], upload_file_params).fail(data =>{
      }).always( data => {
        //go to backend logic after that
        $( "#loadingDiv" ).fadeOut(500, function() {
          $( "#loadingDiv" ).remove(); //makes page more lightweight 
        });
        this.submit();
      } );
      
    });
  }

});