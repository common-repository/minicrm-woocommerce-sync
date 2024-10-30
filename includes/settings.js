(function () {

var PROJECTS_PER_ITERATION = 150

/**
 * A quick sprintf() implementation combined with the translation templates.
 * Only does the very basic, sequential '%s' replacement. No escaping, typing,
 * numbering, etc.
 *
 * @param {string}    template The one used in gettext function on backend
 * @param {...string} param    Any number of params to replace placeholders
 *
 * @return {string} The translated template with the variables.
 */
function __ (template) {
  var placeholder = '%s'
  if (template in minicrmWoocommerceSyncData.translations) {
    template = minicrmWoocommerceSyncData.translations [template]
  }
  var formattedParts = template.split (placeholder)
  if (formattedParts.length === arguments.length) {
    for (var i = arguments.length - 1; i > 0; i --) {
      var param = arguments [i]
      formattedParts.splice (i, 0, param)
    }
  }
  return formattedParts.join ('')
}

/** @return {string} Leaving question */
function confirmExit () {
  return __("The sync hasn't finished yet. Are you sure to leave and abort it?")
}

/**
 * @param {number} seconds
 * @return {string}
 */
function formattedTime (seconds) {
  var minutes = Math.floor (seconds / 60)
  var fractionSecs = seconds % 60
  return `${minutes}:${fractionSecs.toString ().padStart (2, '0')}`
}

/**
 * @param {boolean} test              If syncing with test server
 * @param {Array}   [projectIdsLeft]  Project IDs left to sync.
 * @param {boolean} [hasErrorOccured] Number of failed sync requests.
 * @param {Date}    [start]           Time of sync start
 */
function iterateSyncCycle (test, projectIdsLeft, hasErrorOccured, start) {

  // Error occured
  if (hasErrorOccured) {
    progressText.innerHTML = __('An unexpected <strong class="minicrm-woocommerce-sync-error">error occured</strong>, the sync was aborted. (Please check the Sync log).')
    return
  }

  // Init
  var allCount = minicrmWoocommerceSyncData.projectIds.length

  /**
   * Init first iteration (with 1 argument). We clone the original project IDs
   * array instead of mutating it directly, so the user can run multiple cycles.
   */
  if (arguments.length === 1) {
    var projectIdsLeft = [...minicrmWoocommerceSyncData.projectIds]
    var hasErrorOccured = false
    var start = new Date
    setActiveState (true)
  }

  // Finished, no project IDs left
  if (!projectIdsLeft.length) {
    progressText.innerHTML = __(
      'Finished syncing all (%s) projects. <strong class="minicrm-woocommerce-sync-success">No error</strong> occured, but complete success can only be verified from the Sync log.',
      allCount
    )
    setActiveState (false)
    return
  }

  // Sync next iteration of projects
  var processedCount = allCount - projectIdsLeft.length
  var percent = Math.floor (100 * processedCount / allCount)
  var projectIds = projectIdsLeft.splice (0, PROJECTS_PER_ITERATION)
  jQuery.ajax ({
    data: {
      action:   'sync_projects',
      projects: projectIds.join (','),
      test:     test ? '1' : '0',
    },
    method: 'GET',
    url: minicrmWoocommerceSyncData.ajaxUrl,
    timeout: minicrmWoocommerceSyncData.timeoutInSecs * 1000,
  })
  .error (() => {
    hasErrorOccured = true
  })
  .always (() => {
    iterateSyncCycle (test, projectIdsLeft, hasErrorOccured, start)
  })

  // Calculate remaining seconds
  var remainingSecs = minicrmWoocommerceSyncData.timeoutInSecs
  if (arguments.length) {
    var now = new Date
    var elapsed = (now - start) / 1000
    remainingSecs = Math.ceil (elapsed * projectIdsLeft.length / processedCount)
  }

  // Display progress
  barInside.style.width = `${percent}%`
  progressText.innerHTML = __(
    '<strong>Syncing</strong>... %s% Remaining time: %s Keep the window open to finish.',
    percent.toString ().padStart (3, ' '),
    formattedTime (remainingSecs)
  )
}

/** @param {bool} isActive */
function setActiveState (isActive) {
  btnProd.disabled = isActive
  btnTest.disabled = isActive
  var elemsToMark = [bar, progressText]
  if (isActive) {
    jQuery (window).bind ('beforeunload', confirmExit)
    elemsToMark.forEach (e => { e.classList.add ('active') })
  } else {
    jQuery (window).unbind ('beforeunload', confirmExit)
    elemsToMark.forEach (e => { e.classList.remove ('active') })
  }
}

// Basic environment checks
if (typeof minicrmWoocommerceSyncData !== 'object'){
  return
}
if (typeof jQuery !== 'function') {
  return
}

// Init
var bar          = document.querySelector ('.minicrm-woocommerce-sync-progress-bar')
var barInside    = document.querySelector ('.minicrm-woocommerce-sync-progress-bar-inside')
var btnProd      = document.querySelector ('.minicrm-woocommerce-sync-btn-prod')
var btnTest      = document.querySelector ('.minicrm-woocommerce-sync-btn-test')
var progressText = document.querySelector ('.minicrm-woocommerce-sync-progress-text')

// Add btn click event handlers
jQuery (btnProd).bind ('click', () => {
  iterateSyncCycle (false)
})
jQuery (btnTest).bind ('click', () => {
  iterateSyncCycle (true)
})

}) ()
