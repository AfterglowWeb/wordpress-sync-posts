(function($) {
  'use strict';

  $(document).ready(function() {
    const $syncButton = $('#sync-posts-button');
    const $status = $('#sync-posts-status');
    const $progress = $('#sync-posts-progress');
    const $progressBar = $('#sync-posts-progress-bar-inner');
    const $progressText = $('#sync-posts-progress-text');
    
    let syncData = {
      currentPostTypeIndex: 0,
      page: 1,
      totalPages: 0,
      totalPosts: 0,
      syncedCount: 0
    };
    
    $syncButton.on('click', function() {
      $syncButton.prop('disabled', true);
      $status.html('<p>' + syncPostsData.i18n.syncing + '</p>');
      $progress.show();
      $progressBar.css('width', '0%');
      $progressText.text('0%');
      
      syncData = {
        currentPostTypeIndex: 0,
        page: 1,
        totalPages: 0,
        totalPosts: 0,
        syncedCount: 0
      };
      
      doSync();
    });
    
    function doSync() {
      $.ajax({
        url: syncPostsData.ajax_url,
        type: 'POST',
        data: {
          action: 'sync_posts_start',
          nonce: syncPostsData.nonce,
          post_type_index: syncData.currentPostTypeIndex,
          page: syncData.page
        },
        success: function(response) {
          if (!response.success) {
            handleSyncError(response);
            return;
          }
          
          const data = response.data;
          
          syncData.page = parseInt(data.page);
          syncData.totalPages = parseInt(data.total_pages);
          syncData.totalPosts = parseInt(data.total_posts);
          syncData.syncedCount += parseInt(data.synced_count);
          
          updateProgress(data);
          
          if (!data.is_done) {
            syncData.page++;
            doSync();
          } else {
            if (syncData.currentPostTypeIndex < (data.post_types.length - 1)) {
              syncData.currentPostTypeIndex++;
              syncData.page = 1;
              doSync();
            } else {
              syncCompleted();
            }
          }
        },
        error: function(xhr, status, error) {
          handleSyncError(error);
        }
      });
    }
    
    function updateProgress(data) {
      let totalProgress = 0;
      
      if (data.post_types && data.post_types.length > 1) {
        const postTypeProgress = data.progress / 100;
        const singlePostTypeWeight = 1 / data.post_types.length;
        totalProgress = ((syncData.currentPostTypeIndex * singlePostTypeWeight) + 
                         (postTypeProgress * singlePostTypeWeight)) * 100;
      } else {
        totalProgress = data.progress;
      }
      
      $progressBar.css('width', totalProgress + '%');
      $progressText.text(Math.round(totalProgress) + '%');
      
      const currentPostType = data.post_types ? data.post_types[syncData.currentPostTypeIndex] : '';
      const statusText = `<p>Syncing ${currentPostType}s: Page ${data.page} of ${data.total_pages}</p>` +
                         `<p>Total synced so far: ${syncData.syncedCount}</p>`;
      
      if (data.errors && data.errors.length) {
        $status.html(statusText + '<p>Errors: ' + data.errors.join(', ') + '</p>');
      } else {
        $status.html(statusText);
      }
    }
    
    function syncCompleted() {
      $syncButton.prop('disabled', false);
      $status.html('<p>' + syncPostsData.i18n.success + '</p>' +
                  '<p>Total posts synced: ' + syncData.syncedCount + '</p>');
      $progressBar.css('width', '100%');
      $progressText.text('100%');
    }
    
    function handleSyncError(errorMessage) {
      $syncButton.prop('disabled', false);
      $status.html('<p class="error">' + syncPostsData.i18n.error + errorMessage + '</p>');
    }
  });
})(jQuery);
