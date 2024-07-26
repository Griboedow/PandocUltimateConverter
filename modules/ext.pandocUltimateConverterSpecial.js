$(document).ready(function () {
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
  var source_type = $("#wpConvertSourceType");


  var invalid_regex = /[\/\'\"\$]/g;
  if (submit_form.length == 1) {
    submit_form.on('submit', function (event) {
      event.preventDefault();

      var results;
      var page_name = page_name_field.val()

      // Validate page name
      if (page_name.length < page_name_MIN_LENGTH || page_name.length > page_name_MAX_LENGTH) {
        alert(mw.message('pandocultimateconverter-warning-page-name-length', page_name_MIN_LENGTH, page_name_MAX_LENGTH).text());
        return false;
      }

      if ((results = page_name.match(invalid_regex)) != null) {
        results = results.join(" ");
        alert(mw.message('pandocultimateconverter-warning-page-name-invalid-character', results).text());
        return false;
      }

      if (source_type.val() === 'url') {
        this.submit();
        return;
      }

      if (source_type.val() === 'file') {
        // Validate file selector
        if (!upload_file_field.val()) {
          alert(mw.message('pandocultimateconverter-warning-file-not-selected').text())
          return false;
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

        api.upload(upload_file_field[0], upload_file_params).fail((...resp) => {
          let { upload = null, error = null } = resp[1];
          if (error) {
            switch (error.code) {
              case 'filetype-banned':
                //seconfd arg '$' is a stupid hack: I don't know how to escape $ in mw.messages
                error_msg = mw.message('pandocultimateconverter-error-filetype-banned', ext, '$').text()
                break;
              case 'uploaddisabled':
                error_msg = mw.message('pandocultimateconverter-error-uploaddisabled').text()
                break;
              case 'mustbeloggedin':
                error_msg = mw.message('pandocultimateconverter-error-mustbeloggedin').text()
                break;
              default:
                error_msg = error.code
                break;
            }
            alert(mw.message('pandocultimateconverter-error-generic', error_msg).text())
          }
        }).always(data => {
          //go to backend logic after that
          $("#loadingDiv").fadeOut(500, function () {
            $("#loadingDiv").remove(); //makes page more lightweight 
          });
          this.submit();
          return;
        });
      }


    });
  }

});