<!-- Version Details Modal -->
<div class="modal fade" id="versionModal" tabindex="-1" aria-labelledby="versionModalLabel" aria-hidden="false" data-bs-backdrop="static" data-bs-keyboard="false">
   <div class="modal-dialog modal-lg">
      <div class="modal-content">
         <div class="modal-header bg-danger text-white">
            <h5 class="modal-title" id="versionModalLabel">
               <i class="fas fa-code-branch me-2"></i>Version Details
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
         </div>
         <div class="modal-body">
            <div class="accordion" id="versionAccordion">
               <?php include 'models/semantic-versioning/version2.php'; ?>
            </div>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
               <i class="fas fa-times me-1"></i>Close
            </button>
         </div>
      </div>
   </div>
</div>