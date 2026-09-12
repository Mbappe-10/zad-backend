<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
body { font-size: 11pt; color: #202238; } h1 { color: #6326d6; font-size: 19pt; }
table { border-collapse: collapse; width: 100%; margin-top: 15px; }
th { background: #6326d6; color: white; } td,th { padding: 9px; border: 1px solid #ddd; }
.note { color: #555; font-size: 9pt; } .state { padding: 10px; background: #eee7fa; }
</style></head><body>
<img src="<?php echo e($logo); ?>" style="height:60px" alt="ZAD Sync">
<h1><?php echo e($report['title']); ?></h1>
<p class="state"><?php echo e($state); ?> — رقم التنفيذ <?php echo e($run->id); ?></p>
<p>المصدر: <?php echo e($report['source']); ?><br>وقت استخراج البيانات: <?php echo e($report['captured_at']); ?><br>
الفترة: <?php echo e($report['from']); ?> إلى <?php echo e($report['to_exclusive']); ?> (النهاية غير مشمولة)<br>التوقيت: <?php echo e($report['timezone']); ?></p>
<table><thead><tr><th>المؤشر</th><th>القيمة</th><th>الوحدة</th></tr></thead><tbody>
<?php $__currentLoopData = $report['metrics']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $metric): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<tr><td><?php echo e($metric[0]); ?></td><td><?php echo e($metric[1]); ?></td><td><?php echo e($metric[2]); ?></td></tr>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</tbody></table>
<p>ملاحظة المراجع: <?php echo e($run->review_note ?? 'لم تسجل ملاحظة'); ?></p>
<?php if($run->reviewed_at): ?><p>تاريخ المراجعة UTC: <?php echo e($run->reviewed_at); ?> — معرف المراجع: <?php echo e($run->reviewed_by); ?></p><?php endif; ?>
<?php $__currentLoopData = $report['notes']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $note): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><p class="note">• <?php echo e($note); ?></p><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</body></html>
<?php /**PATH C:\Users\USER\Desktop\ZAD-Project\zad-backend\resources\views/digital-reports/summary.blade.php ENDPATH**/ ?>