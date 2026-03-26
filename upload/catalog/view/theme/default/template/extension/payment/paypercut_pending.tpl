<?php echo $header; ?>
<div class="container">
  <ul class="breadcrumb">
    <li><a href="<?php echo $continue; ?>"><?php echo $text_home; ?></a></li>
    <li><a href="#"><?php echo $heading_title; ?></a></li>
  </ul>
  <div class="row">
    <div id="content" class="col-sm-12">
      <h1><?php echo $heading_title; ?></h1>
      
      <div class="alert alert-warning" style="padding: 30px; margin: 30px 0;">
        <div style="text-align: center;">
          <i class="fa fa-clock-o" style="font-size: 80px; color: #f0ad4e; margin-bottom: 20px;"></i>
          <h2 style="margin-bottom: 10px;">Payment Processing</h2>
          <p style="font-size: 16px; color: #555;"><?php echo $text_message; ?></p>
          
          <?php if ($payment_id) { ?>
          <div style="margin-top: 20px; padding: 15px; background: white; border-radius: 5px; display: inline-block;">
            <p style="margin: 0; font-size: 13px; color: #999;">Transaction ID</p>
            <p style="margin: 5px 0 0 0; font-family: monospace; font-size: 14px; color: #333;"><?php echo $payment_id; ?></p>
          </div>
          <?php } ?>
          
          <div style="margin-top: 25px; padding: 15px; background: #d9edf7; border-radius: 5px; border-left: 4px solid #31708f;">
            <p style="margin: 0; font-size: 14px; color: #31708f;">
              <i class="fa fa-info-circle"></i> <strong>What happens next?</strong>
            </p>
            <ul style="text-align: left; margin: 10px 0 0 20px; font-size: 13px; color: #31708f;">
              <li>Your payment is being processed by your bank</li>
              <li>You will receive an email confirmation once it's complete</li>
              <li>This usually takes a few minutes, but can take up to 24 hours</li>
              <li>You can check your order status in your account</li>
            </ul>
          </div>
        </div>
      </div>
      
      <div class="buttons clearfix">
        <div class="pull-left">
          <a href="<?php echo $continue; ?>" class="btn btn-default">
            <i class="fa fa-home"></i> Continue Shopping
          </a>
        </div>
        <div class="pull-right">
          <a href="<?php echo $order_history; ?>" class="btn btn-primary">
            <i class="fa fa-list"></i> View Order History
          </a>
          <a href="<?php echo $contact; ?>" class="btn btn-info" style="margin-left: 10px;">
            <i class="fa fa-envelope"></i> Contact Us
          </a>
        </div>
      </div>
      
      <?php echo $content_bottom; ?>
    </div>
  </div>
</div>
<?php echo $footer; ?>
