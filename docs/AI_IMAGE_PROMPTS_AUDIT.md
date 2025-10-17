# AI Image Generation Prompts Audit

## Overview

This document provides a comprehensive audit of the AI image generation prompt system used for creating award images in the King Walk Week application. The system generates visual awards for user achievements using configurable prompts that are processed through AI image generation services.

## Current System Architecture

### Storage Location

**Primary Storage:**
- **Database Table:** `settings` table in the main database
- **Keys:**
  - `ai.image.prompts.lifetime` - JSON array of lifetime award prompts

**Code Location:**
- **Default Values:** `api/lib/settings.php` - `settings_seed_defaults()` function
- **Processing Logic:** `api/lib/ai_images.php` - `build_lifetime_award_prompt()` function
- **Admin Interface:** `admin/awards_settings.php` - Web interface for prompt management

### Data Structure

The lifetime prompt set is stored as a JSON array with the following structure:
```json
[
  {
    "name": "Prompt Name",
    "text": "Actual prompt text with {placeholders}",
    "enabled": true
  }
]
```

## Usage Patterns

### When Prompts Are Used

**Lifetime Award Prompts:**
- **Trigger:** Lifetime step milestones (100k, 200k, 500k+)
- **Personalization:** Incorporates user interests from profile
- **Dynamic Elements:** Style hints based on milestone tier
- **Resolution:** 1024x1024 pixels
- **Usage:** `build_lifetime_award_prompt($user, $awardLabel, $milestone)`

### Selection Process

1. **Random Selection:** One enabled lifetime prompt is randomly selected
2. **Fallback Logic:** If no prompts configured or JSON invalid, uses hardcoded fallbacks
3. **Placeholder Replacement:** Variables like `{userName}`, `{milestone}` are replaced with actual values
4. **Interest Integration:** For lifetime awards, randomly selects one user interest

### Integration Points

**API Endpoints:**
- `api/award_generate.php` - Manual award generation
- `api/_award_debug.php` - Testing/debugging interface

**Automatic Triggers:**
- SMS step submissions that cross lifetime milestones
- Award regeneration for missing images

## Current Prompt Inventory

Regular award prompts were deprecated in 2025 during the lifetime-award simplification; only the lifetime prompt set remains active.

### Lifetime Award Prompts (3 total)

**Epic Achievement:**
```
Design a breathtaking digital award image celebrating a lifetime walking achievement. {userName} has reached {milestone} lifetime steps ({awardLabel}). Create a highly detailed, imaginative emblem that visually represents their personality and interest: {interestText}. Use luminous color, depth, and storytelling elements. Capture the feeling of epic accomplishment, motion, and personal triumph. Composition: centered emblem, cinematic lighting, subtle text 'Lifetime {milestone} Steps'. No faces or photo realism. Square 1024x1024 ratio. Style: digital painting + vector hybrid, vivid and collectible. Style hint: {styleHint}.
```

**Mythic Journey:**
```
Create a legendary award illustration for {userName}'s lifetime milestone of {milestone} steps ({awardLabel}). Incorporate their interest in {interestText} into a mythic design with heroic symbolism. Epic scale, dramatic lighting, and profound achievement themes. Square 1024x1024. Style hint: {styleHint}.
```

**Personal Triumph:**
```
Illustrate {userName}'s personal triumph with {milestone} lifetime steps ({awardLabel}). Design around their interest in {interestText} with intimate, meaningful symbolism. Warm colors, personal scale, and authentic achievement feeling. Square 1024x1024. Style hint: {styleHint}.
```

## Strengths of Current System

### ✅ Positive Aspects

1. **Flexible Storage:** JSON-based storage allows easy modification without code changes
2. **Admin Management:** Web interface for prompt editing without developer intervention
3. **Randomization:** Multiple prompts prevent repetitive award designs
4. **Personalization:** Lifetime awards incorporate user interests
5. **Fallback System:** SVG/WebP generation when AI services unavailable
6. **Caching:** 24-hour reuse prevents duplicate API calls for same achievements
7. **Comprehensive Logging:** Detailed logs track generation success/failure

### ✅ Technical Implementation

1. **Error Handling:** Graceful fallback to local generation
2. **Cost Tracking:** Optional cost logging for API usage
3. **Provider Abstraction:** Support for multiple AI image providers
4. **Security:** Input sanitization and validation
5. **Performance:** Efficient caching and reuse logic

## Areas for Improvement

### 🔄 High Priority Improvements

#### 1. Prompt Quality & Variety
**Current Issues:**
- Limited prompt count (3 lifetime prompts)
- Generic style descriptions
- Lack of seasonal/thematic variations

**Recommendations:**
- Expand to 5-7 lifetime prompts
- Add seasonal prompts (holiday themes, seasons)
- Include accessibility-focused prompts (high contrast, clear text)
- Add cultural diversity options

#### 2. Dynamic Personalization
**Current Issues:**
- Basic interest integration
- No user preference consideration
- Limited contextual awareness

**Recommendations:**
- User style preferences (minimalist, vibrant, traditional)
- Time-based themes (morning/evening color schemes)
- Achievement streak bonuses (consecutive week multipliers)
- Geographic/cultural localization

#### 3. Quality Assurance
**Current Issues:**
- No prompt performance metrics
- No user feedback integration
- Limited testing capabilities

**Recommendations:**
- Prompt success rate tracking
- User rating system for generated images
- A/B testing framework for prompt variations
- Automated quality checks (text readability, composition)

### 🔄 Medium Priority Improvements

#### 4. Content Management
**Current Issues:**
- Manual JSON editing in admin interface
- No prompt versioning or history
- Difficult bulk operations

**Recommendations:**
- Rich text editor for prompts
- Prompt tagging and categorization
- Import/export functionality
- Template system for prompt creation

#### 5. Performance Optimization
**Current Issues:**
- Synchronous generation blocking responses
- No batch processing capabilities
- Limited caching strategies

**Recommendations:**
- Asynchronous generation with webhooks
- Batch processing for multiple awards
- Smart caching based on prompt content similarity
- CDN integration for image delivery

#### 6. Analytics & Monitoring
**Current Issues:**
- Basic logging only
- No usage analytics
- Limited debugging tools

**Recommendations:**
- Generation success/failure dashboards
- Cost analysis and optimization
- User engagement metrics
- Performance monitoring

### 🔄 Low Priority Improvements

#### 7. Advanced Features
**Current Issues:**
- Static prompt structure
- No conditional logic
- Limited multimedia support

**Recommendations:**
- Conditional prompts based on user data
- Multi-language prompt support
- Integration with user-uploaded reference images
- Animated award options

#### 8. Developer Experience
**Current Issues:**
- Manual testing process
- Limited debugging tools
- No prompt validation

**Recommendations:**
- Prompt testing sandbox
- Validation rules for placeholders
- Preview generation for admins
- API documentation improvements

## Implementation Recommendations

### Phase 1: Immediate Improvements (1-2 weeks)
1. Add 2-3 new lifetime prompts focusing on variety
2. Implement basic prompt performance tracking
3. Add prompt validation in admin interface
4. Improve error messages and logging

### Phase 2: Enhanced Personalization (2-4 weeks)
1. User style preference system
2. Seasonal prompt rotation
3. Interest-based prompt weighting
4. A/B testing framework

### Phase 3: Advanced Features (4-8 weeks)
1. Asynchronous generation
2. Rich content management interface
3. Analytics dashboard
4. Multi-language support

## Risk Assessment

### Technical Risks
- **API Dependency:** Reliance on external AI services
- **Cost Escalation:** Increased usage with more prompts
- **Performance Impact:** Larger prompt sets may slow selection

### Mitigation Strategies
- **Fallback System:** Robust local generation fallback
- **Caching:** Intelligent reuse of successful prompts
- **Monitoring:** Cost and performance tracking
- **Gradual Rollout:** Feature flags for new functionality

## Success Metrics

### Quantitative Metrics
- Prompt usage distribution (which prompts generate most awards)
- Generation success rate (>95% target)
- User engagement with awards (views, shares)
- API cost per award (<$0.10 target)

### Qualitative Metrics
- User feedback on award appeal
- Admin ease of prompt management
- System reliability and performance
- Creative variety in generated awards

## Conclusion

The current AI image generation prompt system provides a solid foundation with good architectural decisions around flexibility and fallback handling. The main opportunities for improvement lie in expanding prompt variety, enhancing personalization, and adding better management and analytics tools. Implementing these improvements incrementally will maintain system stability while significantly enhancing the user experience with more diverse and engaging award imagery.

## Appendices

### Appendix A: Prompt Template Reference
- `{userName}` - User's display name
- `{milestone}` - Numeric achievement value
- `{awardLabel}` - Human-readable achievement description
- `{interestText}` - Randomly selected user interest
- `{styleHint}` - Dynamic style based on milestone tier

### Appendix B: File Locations
- Settings defaults: `api/lib/settings.php:25-60`
- Prompt processing: `api/lib/ai_images.php:120-180`
- Admin interface: `admin/awards_settings.php:50-100`
- Generation triggers: `api/award_generate.php:1-50`

### Appendix C: Testing Procedures
1. Manual testing via `api/_award_debug.php`
2. Automated tests in `tests/AiImagesTest.php`
3. Admin interface testing in awards settings
4. End-to-end testing through SMS award generation
