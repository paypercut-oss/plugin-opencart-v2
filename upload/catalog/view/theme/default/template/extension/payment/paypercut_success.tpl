<?php echo $header; ?>
<div class="container">
  <ul class="breadcrumb">
    <li><a href="<?php echo $continue; ?>"><?php echo $text_home; ?></a></li>
    <li><a href="#"><?php echo $heading_title; ?></a></li>
  </ul>
  <div class="row">
    <div id="content" class="col-sm-12">
      <h1><?php echo $heading_title; ?></h1>
      
      <div class="alert alert-success" style="padding: 30px; margin: 30px 0;">
        <div style="text-align: center;">
          <i class="fa fa-check-circle" style="font-size: 80px; color: #5cb85c; margin-bottom: 20px;"></i>
          <h2 style="margin-bottom: 10px;">Payment Successful!</h2>
          <p style="font-size: 16px; color: #555;"><?php echo $text_message; ?></p>
          
          <?php if ($payment_id) { ?>
          <div style="margin-top: 20px; padding: 15px; background: white; border-radius: 5px; display: inline-block;">
            <p style="margin: 0; font-size: 13px; color: #999;">Transaction ID</p>
            <p style="margin: 5px 0 0 0; font-family: monospace; font-size: 14px; color: #333;"><?php echo $payment_id; ?></p>
          </div>
          <?php } ?>
          
          <?php if ($payment_method) { ?>
          <div style="margin-top: 15px;">
            <p style="font-size: 14px; color: #666;">
              <i class="fa fa-credit-card"></i> Payment Method: <strong><?php echo $payment_method; ?></strong>
            </p>
          </div>
          <?php } ?>
          
          <?php if ($amount) { ?>
          <div style="margin-top: 10px;">
            <p style="font-size: 14px; color: #666;">
              Amount Charged: <strong><?php echo $amount; ?></strong>
            </p>
          </div>
          <?php } ?>
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
        </div>
      </div>
      
      <?php echo $content_bottom; ?>
    </div>
  </div>
</div>
<?php echo $footer; ?>
