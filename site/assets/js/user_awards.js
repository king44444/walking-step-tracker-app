/**
 * User Awards System - Grid rendering and lightbox gallery
 */

let awardsData = [];
let currentAwardIndex = 0;
let earnedAwards = [];
let touchStartX = 0;

/**
 * Initialize the awards system for a user
 */
async function initUserAwards(userId) {
  showSkeletonLoader();
  
  try {
    const awards = await fetchAwards(userId);
    awardsData = awards;
    renderAwardsGrid(awards);
    initLightbox();
  } catch (error) {
    console.error('Failed to load awards:', error);
    showError('Failed to load awards. Please try again later.');
  }
}

/**
 * Fetch awards from API
 */
async function fetchAwards(userId) {
  const response = await fetch(`../api/user_awards.php?user_id=${userId}&type=lifetime`);
  
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }
  
  const data = await response.json();
  
  if (data.error) {
    throw new Error(data.error);
  }
  
  return data;
}

/**
 * Render the awards grid
 */
function renderAwardsGrid(awards) {
  const container = document.getElementById('awards-grid');
  if (!container) return;
  
  // Sort: earned first (by threshold asc), then locked (by threshold asc)
  const sorted = [...awards].sort((a, b) => {
    if (a.earned && !b.earned) return -1;
    if (!a.earned && b.earned) return 1;
    return a.threshold - b.threshold;
  });
  
  // Filter earned awards for lightbox navigation
  earnedAwards = sorted.filter(a => a.earned);
  
  container.innerHTML = sorted.map((award, index) => {
    const statusClass = award.earned ? 'earned' : 'locked';
    const statusText = award.earned 
      ? `Earned · ${formatDate(award.awarded_at)}`
      : `Locked · needs ${award.title.toLowerCase()}`;
    
    const clickable = award.earned ? `onclick="openLightbox(${earnedAwards.findIndex(a => a.key === award.key)})"` : '';
    const tabindex = award.earned ? '0' : '-1';
    const ariaLabel = award.earned 
      ? `Open award ${award.title}, earned ${formatDate(award.awarded_at)}`
      : `${award.title}, locked`;
    
    return `
      <button 
        class="award-card ${statusClass}" 
        ${clickable}
        tabindex="${tabindex}"
        aria-label="${ariaLabel}"
        ${award.earned ? `onkeydown="handleCardKeydown(event, ${earnedAwards.findIndex(a => a.key === award.key)})"` : ''}
      >
        <img 
          src="${award.thumb_url}" 
          alt="${award.title}"
          class="award-image"
          onerror="handleImageError(this)"
          loading="lazy"
        />
        <div class="award-title">${award.title}</div>
        <div class="award-status ${statusClass}">${statusText}</div>
      </button>
    `;
  }).join('');
}

/**
 * Handle keyboard events on award cards
 */
function handleCardKeydown(event, awardIndex) {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    openLightbox(awardIndex);
  }
}

/**
 * Show skeleton loader while fetching
 */
function showSkeletonLoader() {
  const container = document.getElementById('awards-grid');
  if (!container) return;
  
  container.innerHTML = Array(5).fill(0).map(() => `
    <div class="award-skeleton">
      <div class="award-skeleton-image"></div>
      <div class="award-skeleton-title"></div>
      <div class="award-skeleton-status"></div>
    </div>
  `).join('');
}

/**
 * Show error message
 */
function showError(message) {
  const container = document.getElementById('awards-grid');
  if (!container) return;
  
  container.innerHTML = `
    <div style="grid-column: 1 / -1; text-align: center; padding: 2rem; color: rgba(230, 236, 255, 0.6);">
      ${message}
    </div>
  `;
}

/**
 * Format ISO date for display
 */
function formatDate(isoDate) {
  if (!isoDate) return '';
  
  try {
    const date = new Date(isoDate + 'T00:00:00');
    return date.toLocaleDateString('en-US', { 
      year: 'numeric', 
      month: 'short', 
      day: 'numeric' 
    });
  } catch (e) {
    return isoDate;
  }
}

/**
 * Handle broken images
 */
function handleImageError(img) {
  img.src = '/site/assets/awards/default/lifetime_default.svg';
  img.onerror = null; // Prevent infinite loop
}

/**
 * Initialize lightbox functionality
 */
function initLightbox() {
  const lightbox = document.getElementById('lightbox');
  if (!lightbox) return;
  
  const closeBtn = lightbox.querySelector('.lightbox-close');
  const prevBtn = lightbox.querySelector('.lightbox-prev');
  const nextBtn = lightbox.querySelector('.lightbox-next');
  const backdrop = lightbox.querySelector('.lightbox-backdrop');
  
  // Close handlers
  closeBtn?.addEventListener('click', closeLightbox);
  backdrop?.addEventListener('click', closeLightbox);
  
  // Navigation handlers
  prevBtn?.addEventListener('click', () => navigateAward(-1));
  nextBtn?.addEventListener('click', () => navigateAward(1));
  
  // Keyboard navigation
  document.addEventListener('keydown', handleLightboxKeydown);
  
  // Touch swipe
  const content = lightbox.querySelector('.lightbox-content');
  if (content) {
    content.addEventListener('touchstart', handleTouchStart, { passive: true });
    content.addEventListener('touchend', handleTouchEnd, { passive: true });
  }
}

/**
 * Open lightbox with specific award
 */
function openLightbox(index) {
  if (index < 0 || index >= earnedAwards.length) return;
  
  currentAwardIndex = index;
  const award = earnedAwards[index];
  
  const lightbox = document.getElementById('lightbox');
  const img = document.getElementById('lb-img');
  const title = lightbox.querySelector('.lightbox-title');
  const date = lightbox.querySelector('.lightbox-date');
  
  if (!lightbox || !img || !title || !date) return;
  
  // Update content
  img.src = award.image_url;
  img.alt = award.title;
  img.onerror = () => handleImageError(img);
  title.textContent = award.title;
  date.textContent = `Earned ${formatDate(award.awarded_at)}`;
  
  // Show lightbox
  lightbox.removeAttribute('hidden');
  document.body.style.overflow = 'hidden';
  
  // Focus close button for accessibility
  const closeBtn = lightbox.querySelector('.lightbox-close');
  if (closeBtn) {
    setTimeout(() => closeBtn.focus(), 100);
  }
}

/**
 * Close lightbox
 */
function closeLightbox() {
  const lightbox = document.getElementById('lightbox');
  if (!lightbox) return;
  
  lightbox.setAttribute('hidden', '');
  document.body.style.overflow = '';
  
  // Return focus to the award card that was opened
  const cards = document.querySelectorAll('.award-card.earned');
  if (cards[currentAwardIndex]) {
    cards[currentAwardIndex].focus();
  }
}

/**
 * Navigate to prev/next award
 */
function navigateAward(direction) {
  const newIndex = currentAwardIndex + direction;
  
  // Wrap around
  if (newIndex < 0) {
    currentAwardIndex = earnedAwards.length - 1;
  } else if (newIndex >= earnedAwards.length) {
    currentAwardIndex = 0;
  } else {
    currentAwardIndex = newIndex;
  }
  
  openLightbox(currentAwardIndex);
}

/**
 * Handle keyboard events in lightbox
 */
function handleLightboxKeydown(event) {
  const lightbox = document.getElementById('lightbox');
  if (!lightbox || lightbox.hasAttribute('hidden')) return;
  
  switch (event.key) {
    case 'Escape':
      event.preventDefault();
      closeLightbox();
      break;
    case 'ArrowLeft':
      event.preventDefault();
      navigateAward(-1);
      break;
    case 'ArrowRight':
      event.preventDefault();
      navigateAward(1);
      break;
    case 'Tab':
      // Focus trap: keep focus within lightbox
      handleFocusTrap(event);
      break;
  }
}

/**
 * Handle focus trap in lightbox
 */
function handleFocusTrap(event) {
  const lightbox = document.getElementById('lightbox');
  if (!lightbox) return;
  
  const focusableElements = lightbox.querySelectorAll(
    'button:not([disabled]), [tabindex]:not([tabindex="-1"])'
  );
  const firstElement = focusableElements[0];
  const lastElement = focusableElements[focusableElements.length - 1];
  
  if (event.shiftKey) {
    // Shift + Tab
    if (document.activeElement === firstElement) {
      event.preventDefault();
      lastElement.focus();
    }
  } else {
    // Tab
    if (document.activeElement === lastElement) {
      event.preventDefault();
      firstElement.focus();
    }
  }
}

/**
 * Handle touch start for swipe detection
 */
function handleTouchStart(event) {
  touchStartX = event.touches[0].clientX;
}

/**
 * Handle touch end for swipe detection
 */
function handleTouchEnd(event) {
  const touchEndX = event.changedTouches[0].clientX;
  const diff = touchStartX - touchEndX;
  const threshold = 40;
  
  if (Math.abs(diff) > threshold) {
    if (diff > 0) {
      // Swipe left - next
      navigateAward(1);
    } else {
      // Swipe right - prev
      navigateAward(-1);
    }
  }
}
