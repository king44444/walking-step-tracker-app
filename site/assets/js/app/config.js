export let DAY_ORDER = ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];
export let DAILY_GOAL_10K = 10000;
export let APP_VERSION = "0.1.3";
export let DAILY_GOAL_15K = 15000;
export let DAILY_GOAL_2_5K = 2500;
export let DAILY_GOAL_1K = 1000;
export let CHERYL_THRESHOLD = 20000;
export let THIRTY_K_THRESHOLD = 30000;
export let AWARD_LIMIT = 2;
export let DISPLAY_NAME_OVERRIDES = { "Tutu": "Tutu" };

export let LEVEL_K = 1500;
export let LEVEL_P = 1.6;
export let LEVEL_LABEL = "Level";

export let NUDGES = [
  "Your shoes miss you.",
  "Take a lap and report back.",
  "Screenshot the counter tonight.",
  "Walk-n-talk with the fam, then log it.",
  "30 minutes. No debate."
];

export let CUSTOM_AWARD_LABELS = {};
export let LIFETIME_STEP_MILESTONES = [100000,250000,500000,1000000];
export let LIFETIME_ATTENDANCE_MILESTONES = [25,50,100];

// Compute base URL like: /dev/html/walk/
export const BASE = (() => {
  const m = location.pathname.match(/^(.*\/)site\/(?:$|)/);
  return m ? m[1] : '/';
})();

export async function loadConfig() {
  try {
    const res = await fetch(`${BASE}site/config.json`, { cache: "no-store" });
    if (!res.ok) return;
    const cfg = await res.json();
    DAY_ORDER = cfg.DAY_ORDER || DAY_ORDER;
    DAILY_GOAL_10K = cfg.GOALS?.DAILY_GOAL_10K ?? DAILY_GOAL_10K;
    DAILY_GOAL_15K = cfg.GOALS?.DAILY_GOAL_15K ?? DAILY_GOAL_15K;
    DAILY_GOAL_2_5K = cfg.GOALS?.DAILY_GOAL_2_5K ?? DAILY_GOAL_2_5K;
    DAILY_GOAL_1K = cfg.GOALS?.DAILY_GOAL_1K ?? DAILY_GOAL_1K;
    CHERYL_THRESHOLD = cfg.THRESHOLDS?.CHERYL_THRESHOLD ?? CHERYL_THRESHOLD;
    THIRTY_K_THRESHOLD = cfg.THRESHOLDS?.THIRTY_K_THRESHOLD ?? THIRTY_K_THRESHOLD;
    // allow overriding how many awards a single person can win
    AWARD_LIMIT = Number.isFinite(Number(cfg.AWARD_LIMIT)) ? Number(cfg.AWARD_LIMIT) : AWARD_LIMIT;
    DISPLAY_NAME_OVERRIDES = cfg.DISPLAY_NAME_OVERRIDES || DISPLAY_NAME_OVERRIDES;
    NUDGES = cfg.NUDGES || NUDGES;
    APP_VERSION = cfg.APP_VERSION || APP_VERSION;
    CUSTOM_AWARD_LABELS = cfg.CUSTOM_AWARD_LABELS || CUSTOM_AWARD_LABELS;
    LIFETIME_STEP_MILESTONES = cfg.LIFETIME_STEP_MILESTONES || LIFETIME_STEP_MILESTONES;
    LIFETIME_ATTENDANCE_MILESTONES = cfg.LIFETIME_ATTENDANCE_MILESTONES || LIFETIME_ATTENDANCE_MILESTONES;
  LEVEL_K = cfg.LEVELS?.K ?? LEVEL_K;
  LEVEL_P = cfg.LEVELS?.P ?? LEVEL_P;
  LEVEL_LABEL = cfg.LEVELS?.LABEL ?? LEVEL_LABEL;
  } catch (e) {
    console.warn('Failed to load config, using defaults', e);
  }
}

