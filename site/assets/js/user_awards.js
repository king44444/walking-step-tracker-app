/**
 * User Awards System - Grid rendering and lightbox gallery
 */

let currentAwardIndex = 0;
let earnedAwards = [];
let touchStartX = 0;

const SECTION_CONFIG = {
  lifetime_steps: {
    containerId: 'awards-grid-steps',
    emptyMessage: 'No lifetime step awards earned yet.',
  },
  attendance_days: {
    containerId: 'awards-grid-attendance',
    emptyMessage: 'No lifetime attendance awards earned yet.',
  },
};

/**
 * Initialize the awards system for a user
 */
async function initUserAwards(userId) {
  showSkeletonLoader();
  
  try {
    const sections = await fetchAwards(userId);
    renderAwardSections(sections);
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
  
  if (Array.isArray(data.sections)) {
    return data.sections;
  }

  return [];
}

/**
 * Render award sections into their containers
 */
function renderAwardSections(sections) {
  earnedAwards = [];

  const list = Array.isArray(sections) ? sections : [];

  Object.entries(SECTION_CONFIG).forEach(([kind, config]) => {
    const section = list.find(sec => (sec.kind || sec.id) === kind) || null;
    renderAwardSection(section, config);
  });
}

/**
 * Render a single award section
 */
function renderAwardSection(section, config) {
  const container = document.getElementById(config.containerId);
  if (!container) return;

  const awards = Array.isArray(section?.awards) ? section.awards : [];
  const earnedOnly = awards
    .filter(a => a && a.earned)
    .sort((a, b) => a.threshold - b.threshold);

  if (earnedOnly.length === 0) {
    renderEmptyState(container, config.emptyMessage);
    return;
  }

  container.innerHTML = earnedOnly.map(award => {
    const globalIndex = registerEarnedAward(award);
    const statusClass = 'earned';
    const countText = (typeof award.count === 'number' && award.count > 0) ? ` · ${award.count}x` : '';
    const statusText = award.awarded_at
      ? `Earned · ${formatDate(award.awarded_at)}${countText}`
      : `Earned${countText}`;
    const imageSrc = award.thumb_url || award.image_url || 'assets/admin/no-photo.svg';
    const ariaLabel = `Open award ${award.title}${award.awarded_at ? ', earned ' + formatDate(award.awarded_at) : ''}`;

    return `
      <button 
        class="award-card ${statusClass}" 
        onclick="openLightbox(${globalIndex})"
        tabindex="0"
        aria-label="${ariaLabel}"
        onkeydown="handleCardKeydown(event, ${globalIndex})"
      >
        <img 
          src="${imageSrc}" 
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

function registerEarnedAward(award) {
  earnedAwards.push(award);
  return earnedAwards.length - 1;
}

function renderEmptyState(container, message) {
  container.innerHTML = `
    <div style="grid-column: 1 / -1; text-align: center; padding: 2rem; color: rgba(230, 236, 255, 0.6);">
      ${message}
    </div>
  `;
}

function buildSkeletonMarkup() {
  return Array(5).fill(0).map(() => `
    <div class="award-skeleton">
      <div class="award-skeleton-image"></div>
      <div class="award-skeleton-title"></div>
      <div class="award-skeleton-status"></div>
    </div>
  `).join('');
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
  Object.values(SECTION_CONFIG).forEach(config => {
    const container = document.getElementById(config.containerId);
    if (!container) return;
    container.innerHTML = buildSkeletonMarkup();
  });
}

/**
 * Show error message
 */
function showError(message) {
  Object.values(SECTION_CONFIG).forEach(config => {
    const container = document.getElementById(config.containerId);
    if (!container) return;
    renderEmptyState(container, message);
  });
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
  img.src = 'assets/admin/no-photo.svg';
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
