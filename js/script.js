jQuery(document).ready(function() {
  jQuery('input.jImageChooser').change(function () {
    var value = jQuery(this).val();
    var settings_field_id = jQuery(this).attr('name').replace("_chooser", "");
    var input_name = 'lynk_responder_options['+settings_field_id+']';
    
    switch(value)
    {
      case 'keep':
        jQuery('a#'+settings_field_id).show();
        jQuery('input#'+settings_field_id).hide();
        
        jQuery('#hidden-'+settings_field_id).attr('name', input_name);
        jQuery('input#'+settings_field_id).attr('name', '');
        break;
      case 'new':
        jQuery('input#'+settings_field_id).show();
        jQuery('a#'+settings_field_id).hide();
        
        jQuery('input#'+settings_field_id).attr('name', input_name);
        jQuery('#hidden-'+settings_field_id).attr('name', '');
        break;
      case 'delete':
        jQuery('input#'+settings_field_id).hide();
        jQuery('a#'+settings_field_id).hide();
        
        jQuery('input#'+settings_field_id).attr('name', input_name);
        jQuery('#hidden-'+settings_field_id).attr('name', '');
        break;
    }
    
  });
});