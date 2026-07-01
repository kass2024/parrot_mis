/**
 * Shared Zoom Client View helpers — init options + screen-share layout.
 */
(function (global) {
  'use strict';

  var shareWatchTimer = null;

  function errMsg(err) {
    if (!err || typeof err !== 'object') return 'Zoom error';
    return err.reason || err.message || 'Zoom error';
  }

  function isSharingActive() {
    var root = document.getElementById('zmmtg-root');
    if (!root) return false;
    var text = (root.innerText || '').toLowerCase();
    return text.indexOf('screen sharing') !== -1
      || text.indexOf("you're screen sharing") !== -1
      || text.indexOf('you are viewing') !== -1
      || text.indexOf("'s screen") !== -1
      || text.indexOf('view options') !== -1;
  }

  function dockParticipantTiles() {
    var root = document.getElementById('zmmtg-root');
    if (!root) return;

    var selectors = [
      '[class*="video-avatar"]',
      '[class*="VideoAvatar"]',
      '[class*="self-video"]',
      '[class*="SelfVideo"]',
      '[class*="float-active"]',
      '[class*="FloatActive"]',
      '[class*="participant-video"]',
      '[class*="ParticipantVideo"]'
    ];

    selectors.forEach(function (sel) {
      root.querySelectorAll(sel).forEach(function (el) {
        if (!el || el.closest('[class*="share"]') || el.closest('[class*="Share"]')) {
          return;
        }
        var rect = el.getBoundingClientRect();
        if (rect.width < 40 || rect.height < 40) return;
        if (rect.width > window.innerWidth * 0.75) return;
        el.classList.add('fm-docked-participant-tile');
      });
    });
  }

  function applyPureShareLayout() {
    document.body.classList.add('fm-zoom-share-active');
    if (typeof ZoomMtg !== 'undefined' && typeof ZoomMtg.showPureSharingContent === 'function') {
      try {
        ZoomMtg.showPureSharingContent({ show: true });
      } catch (e) { /* ignore */ }
    }
    dockParticipantTiles();
  }

  function clearShareLayout() {
    document.body.classList.remove('fm-zoom-share-active');
    var root = document.getElementById('zmmtg-root');
    if (root) {
      root.querySelectorAll('.fm-docked-participant-tile').forEach(function (el) {
        el.classList.remove('fm-docked-participant-tile');
      });
    }
    if (typeof ZoomMtg !== 'undefined' && typeof ZoomMtg.showPureSharingContent === 'function') {
      try {
        ZoomMtg.showPureSharingContent({ show: false });
      } catch (e) { /* ignore */ }
    }
  }

  function syncShareLayout() {
    if (isSharingActive()) {
      applyPureShareLayout();
    } else {
      clearShareLayout();
    }
  }

  function watchShareLayout() {
    if (shareWatchTimer) return;
    shareWatchTimer = window.setInterval(syncShareLayout, 1500);
    syncShareLayout();

    if (typeof ZoomMtg === 'undefined' || typeof ZoomMtg.inMeetingServiceListener !== 'function') {
      return;
    }

    ['onShareContentChange', 'receiveSharingChannelReady', 'onMeetingStatus'].forEach(function (evt) {
      try {
        ZoomMtg.inMeetingServiceListener(evt, function () {
          window.setTimeout(syncShareLayout, 300);
        });
      } catch (e) { /* ignore */ }
    });
  }

  function getInitOptions(leaveUrl) {
    return {
      leaveUrl: leaveUrl,
      patchJsMedia: true,
      leaveOnPageUnload: true,
      showPureSharingContent: true,
      sharingMode: 'fit',
      defaultView: 'speaker',
      videoDrag: true,
      showMeetingHeader: false,
      videoHeader: false
    };
  }

  function prepareSdk(zoomLibUrl) {
    return new Promise(function (resolve, reject) {
      try {
        if (typeof ZoomMtg.setZoomJSLib === 'function') {
          ZoomMtg.setZoomJSLib(zoomLibUrl, '/av');
        }
        ZoomMtg.preLoadWasm();
        var prep = ZoomMtg.prepareWebSDK();
        if (prep && typeof prep.then === 'function') {
          prep.then(resolve).catch(reject);
        } else {
          window.setTimeout(resolve, 500);
        }
      } catch (e) {
        reject(e);
      }
    });
  }

  function initClient(leaveUrl) {
    return new Promise(function (resolve, reject) {
      var opts = getInitOptions(leaveUrl);
      opts.success = function () {
        watchShareLayout();
        resolve();
      };
      opts.error = function (err) {
        reject(new Error(errMsg(err)));
      };
      ZoomMtg.init(opts);
    });
  }

  global.FmZoomRoom = {
    errMsg: errMsg,
    prepareSdk: prepareSdk,
    initClient: initClient,
    watchShareLayout: watchShareLayout,
    syncShareLayout: syncShareLayout,
    applyPureShareLayout: applyPureShareLayout
  };
})(window);
