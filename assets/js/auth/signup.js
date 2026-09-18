/**
 * signup.js — 5-step signup flow
 * Steps: 1 Personal Info → 2 Academic → 3 Focus Areas → 4 Interests → 5 Hobbies
 */

'use strict';

// ── State ──────────────────────────────────────────────────────────────────
let currentStep = 1;
const TOTAL_STEPS = 5;

// Hobby state: array of { hobby, genre, title, hoursPerMonth, playstyle, experience }
let hobbies = [];
let activeHobbyCard = null; // which main hobby card is expanded

// ── Step metadata ──────────────────────────────────────────────────────────
const STEP_LABELS = [
  'Personal Info',
  'Year Level & Course',
  'Focus Areas',
  'Interests',
  'Hobbies',
];

// ── Hobby taxonomy ─────────────────────────────────────────────────────────
const HOBBY_DATA = [
  {
    hobby: 'Gaming', icon: '🎮',
    genres: ['FPS', 'MOBA', 'MMORPG', 'Sandbox', 'Strategy', 'Horror', 'Rhythm', 'Battle Royale', 'RPG', 'Sports'],
    titles: {
      'FPS':          ['Valorant', 'CS2', 'Overwatch 2', 'Apex Legends', 'Call of Duty'],
      'MOBA':         ['League of Legends', 'Dota 2', 'Mobile Legends', 'Honor of Kings'],
      'MMORPG':       ['Warframe', 'Genshin Impact', 'Final Fantasy XIV', 'Black Desert'],
      'Sandbox':      ['Minecraft', 'Terraria', 'Roblox', 'Stardew Valley'],
      'Strategy':     ['Clash of Clans', 'Age of Empires', 'Civilization VI', 'StarCraft II'],
      'Horror':       ['Phasmophobia', 'Dead by Daylight', 'Resident Evil', 'Lethal Company'],
      'Rhythm':       ['osu!', 'Geometry Dash', 'Beat Saber', 'Friday Night Funkin'],
      'Battle Royale':['PUBG', 'Fortnite', 'Free Fire', 'Warzone'],
      'RPG':          ['Elden Ring', 'Baldur\'s Gate 3', 'Persona 5', 'The Witcher 3'],
      'Sports':       ['FIFA / EA FC', 'NBA 2K', 'eFootball', 'Rocket League'],
    },
    playstyles: ['Casual', 'Competitive', 'Co-op', 'Solo', 'Streaming / Content'],
    experiences: ['Beginner', 'Intermediate', 'Advanced', 'Expert'],
  },
  {
    hobby: 'Music', icon: '🎵',
    genres: ['Rock', 'Pop', 'Hip-Hop / Rap', 'Jazz', 'Classical', 'R&B', 'Electronic / EDM', 'OPM', 'K-Pop', 'Metal'],
    titles: {
      'Rock':             ['Linkin Park', 'Green Day', 'Paramore', 'Arctic Monkeys', 'Nirvana'],
      'Pop':              ['Taylor Swift', 'Billie Eilish', 'Ed Sheeran', 'Olivia Rodrigo'],
      'Hip-Hop / Rap':    ['Kendrick Lamar', 'Drake', 'Eminem', 'Travis Scott', 'J. Cole'],
      'Jazz':             ['Miles Davis', 'John Coltrane', 'Norah Jones', 'Chet Baker'],
      'Classical':        ['Beethoven', 'Mozart', 'Chopin', 'Bach', 'Debussy'],
      'R&B':              ['Frank Ocean', 'SZA', 'The Weeknd', 'H.E.R.', 'Beyoncé'],
      'Electronic / EDM': ['Martin Garrix', 'Marshmello', 'Daft Punk', 'Flume', 'Porter Robinson'],
      'OPM':              ['Ben&Ben', 'December Avenue', 'IV of Spades', 'SB19', 'BINI'],
      'K-Pop':            ['BTS', 'BLACKPINK', 'aespa', 'NewJeans', 'Stray Kids'],
      'Metal':            ['Metallica', 'Slipknot', 'System of a Down', 'Bring Me the Horizon'],
    },
    playstyles: ['Listener', 'Musician / Player', 'Singer', 'Producer', 'Concert-goer'],
    experiences: ['Casual Listener', 'Active Follower', 'Musician', 'Producer / Creator'],
  },
  {
    hobby: 'Coding', icon: '💻',
    genres: ['Web Dev', 'Mobile Dev', 'AI / ML', 'Game Dev', 'Competitive Programming', 'Open Source', 'Automation', 'Embedded / IoT'],
    titles: {
      'Web Dev':                  ['React', 'Vue', 'Next.js', 'Laravel', 'Django', 'Svelte'],
      'Mobile Dev':               ['Flutter', 'React Native', 'Swift', 'Kotlin', 'Expo'],
      'AI / ML':                  ['TensorFlow', 'PyTorch', 'Hugging Face', 'OpenCV', 'LangChain'],
      'Game Dev':                 ['Unity', 'Unreal Engine', 'Godot', 'Pygame', 'GameMaker'],
      'Competitive Programming':  ['LeetCode', 'Codeforces', 'HackerRank', 'AtCoder'],
      'Open Source':              ['GitHub contributions', 'Linux', 'Firefox', 'VS Code'],
      'Automation':               ['Python scripts', 'Selenium', 'n8n', 'Zapier', 'Bash'],
      'Embedded / IoT':           ['Arduino', 'Raspberry Pi', 'ESP32', 'MicroPython'],
    },
    playstyles: ['For Fun', 'Freelancing', 'Open Source Contributor', 'Building Products', 'Learning / Studying'],
    experiences: ['Beginner', 'Intermediate', 'Advanced', 'Professional'],
  },
  {
    hobby: 'Fitness', icon: '🏋️',
    genres: ['Gym / Weightlifting', 'Running', 'Cycling', 'Martial Arts', 'Team Sports', 'Yoga / Pilates', 'Swimming', 'Dance'],
    titles: {
      'Gym / Weightlifting':  ['Powerlifting', 'Bodybuilding', 'Calisthenics', 'CrossFit'],
      'Running':              ['5K', '10K', 'Half-marathon', 'Marathon', 'Trail Running'],
      'Cycling':              ['Road Cycling', 'Mountain Biking', 'BMX', 'Spin Class'],
      'Martial Arts':         ['Boxing', 'MMA', 'Muay Thai', 'BJJ', 'Taekwondo', 'Judo'],
      'Team Sports':          ['Basketball', 'Football / Soccer', 'Volleyball', 'Badminton', 'Table Tennis'],
      'Yoga / Pilates':       ['Hatha', 'Vinyasa', 'Ashtanga', 'Pilates Mat', 'Aerial Yoga'],
      'Swimming':             ['Freestyle', 'Breaststroke', 'Competitive', 'Open Water'],
      'Dance':                ['Hip-Hop', 'Contemporary', 'K-Pop Cover', 'Ballroom', 'Tinikling'],
    },
    playstyles: ['Casual', 'Competitive', 'Training Partner', 'Coach / Trainer', 'Solo Training'],
    experiences: ['Beginner', 'Intermediate', 'Advanced', 'Athlete'],
  },
  {
    hobby: 'Reading', icon: '📚',
    genres: ['Fiction', 'Non-Fiction', 'Sci-Fi / Fantasy', 'Self-Help', 'Manga / Manhwa', 'Light Novels', 'Comics', 'Academic'],
    titles: {
      'Fiction':          ['Harry Potter', 'The Alchemist', 'Pride and Prejudice', '1984'],
      'Non-Fiction':      ['Atomic Habits', 'Sapiens', 'Thinking Fast and Slow', 'The 48 Laws of Power'],
      'Sci-Fi / Fantasy': ['Dune', 'The Martian', 'Mistborn', 'The Name of the Wind'],
      'Self-Help':        ['Deep Work', 'The 7 Habits', 'Can\'t Hurt Me', 'The Psychology of Money'],
      'Manga / Manhwa':   ['One Piece', 'Attack on Titan', 'Solo Leveling', 'Jujutsu Kaisen', 'Berserk'],
      'Light Novels':     ['Sword Art Online', 'Re:Zero', 'Overlord', 'No Game No Life'],
      'Comics':           ['Marvel', 'DC', 'Image Comics', 'Webtoon'],
      'Academic':         ['Textbooks', 'Research Papers', 'Case Studies', 'Journals'],
    },
    playstyles: ['Leisure Reader', 'Avid Reader', 'Book Club', 'Reviewer / Critic', 'Collector'],
    experiences: ['Occasional', 'Monthly', 'Weekly', 'Daily Reader'],
  },
  {
    hobby: 'Photography', icon: '📷',
    genres: ['Portrait', 'Street', 'Landscape', 'Astrophotography', 'Wildlife', 'Product', 'Event', 'Macro'],
    titles: {
      'Portrait':         ['Studio', 'Outdoor', 'Fashion', 'Boudoir'],
      'Street':           ['Urban', 'Documentary', 'Candid'],
      'Landscape':        ['Golden Hour', 'Long Exposure', 'Aerial / Drone'],
      'Astrophotography': ['Milky Way', 'Planetary', 'Deep Sky'],
      'Wildlife':         ['Birds', 'Macro Insects', 'Safari', 'Underwater'],
      'Product':          ['Food', 'Tech', 'Fashion', 'Flat Lay'],
      'Event':            ['Weddings', 'Concerts', 'Sports', 'Graduation'],
      'Macro':            ['Flowers', 'Insects', 'Textures', 'Water Drops'],
    },
    playstyles: ['Hobbyist', 'Freelancer', 'Content Creator', 'Photo Journalist', 'Fine Art'],
    experiences: ['Beginner', 'Enthusiast', 'Semi-Pro', 'Professional'],
  },
  {
    hobby: 'Anime / Manga', icon: '⛩️',
    genres: ['Shonen', 'Shojo', 'Seinen', 'Isekai', 'Mecha', 'Slice of Life', 'Horror', 'Sports', 'Romance', 'Fantasy'],
    titles: {
      'Shonen':        ['One Piece', 'Dragon Ball', 'Bleach', 'Demon Slayer', 'My Hero Academia'],
      'Shojo':         ['Sailor Moon', 'Fruits Basket', 'Ouran Host Club', 'Your Lie in April'],
      'Seinen':        ['Berserk', 'Vinland Saga', 'Vagabond', 'Mushishi', 'Tokyo Ghoul'],
      'Isekai':        ['Re:Zero', 'Overlord', 'Konosuba', 'Sword Art Online', 'That Time I Got Reincarnated'],
      'Mecha':         ['Neon Genesis Evangelion', 'Gurren Lagann', 'Code Geass', 'Gundam'],
      'Slice of Life': ['Laid-Back Camp', 'March Comes in Like a Lion', 'Barakamon'],
      'Horror':        ['Attack on Titan', 'Promised Neverland', 'Junji Ito', 'Paranoia Agent'],
      'Sports':        ['Haikyuu', 'Kuroko\'s Basketball', 'Yuri on Ice', 'Ping Pong'],
      'Romance':       ['Toradora', 'Ore Monogatari', 'Clannad', 'Your Name', 'Horimiya'],
      'Fantasy':       ['Fullmetal Alchemist', 'Made in Abyss', 'Hunter x Hunter', 'Fairy Tail'],
    },
    playstyles: ['Casual Viewer', 'Binge Watcher', 'Manga Reader', 'Cosplayer', 'Collector / Figurines'],
    experiences: ['Newcomer', 'Casual Fan', 'Regular Watcher', 'Hardcore Otaku'],
  },
  {
    hobby: 'Travel', icon: '✈️',
    genres: ['Backpacking', 'Beach / Island', 'Mountain / Trekking', 'City Hopping', 'Food Tourism', 'Cultural / Heritage', 'Road Trips', 'Budget Travel'],
    titles: {
      'Backpacking':          ['Southeast Asia', 'Europe', 'South America', 'South Korea'],
      'Beach / Island':       ['Palawan', 'Siargao', 'Boracay', 'Bali', 'Maldives'],
      'Mountain / Trekking':  ['Mt. Apo', 'Mt. Pulag', 'Mt. Fuji', 'Himalayas'],
      'City Hopping':         ['Tokyo', 'Seoul', 'Barcelona', 'New York', 'London'],
      'Food Tourism':         ['Street Food Tours', 'Michelin Restaurants', 'Night Markets'],
      'Cultural / Heritage':  ['Intramuros', 'Angkor Wat', 'Machu Picchu', 'Rome'],
      'Road Trips':           ['Luzon loop', 'Pacific Coast Highway', 'Route 66'],
      'Budget Travel':        ['Hostels', 'Couchsurfing', 'Airline Miles Hacks'],
    },
    playstyles: ['Solo Traveler', 'Group Travel', 'Family Trips', 'Travel Blogger', 'Spontaneous'],
    experiences: ['Occasional', 'Seasonal', 'Frequent', 'Digital Nomad'],
  },
];

// ── Helpers ────────────────────────────────────────────────────────────────
function getCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function showErr(step, msg) {
  const el = document.getElementById('err' + step);
  if (!el) return;
  el.textContent = msg;
  el.style.display = msg ? 'block' : 'none';
}

function clearErr(step) { showErr(step, ''); }

function setLoading(loading) {
  const btn = document.getElementById('submitBtn');
  if (!btn) return;
  if (loading) {
    btn.innerHTML = '<span class="btn-spinner"></span>Creating Account…';
    btn.disabled = true;
  } else {
    btn.textContent = 'Create Account';
    btn.disabled = false;
  }
}

// ── Step navigation ────────────────────────────────────────────────────────
function showStep(n) {
  for (let i = 1; i <= TOTAL_STEPS; i++) {
    document.getElementById('panel' + i)?.classList.toggle('active', i === n);
    document.getElementById('dot' + i)?.classList.toggle('active', i <= n);
  }
  const topBack = document.getElementById('topBackBtn');
  if (topBack) topBack.style.display = n === 1 ? 'grid' : 'none';

  const ssoButtons = document.getElementById('ssoButtons');
  if (ssoButtons) ssoButtons.style.display = n === 1 ? '' : 'none';

  const labelEl = document.getElementById('stepLabel');
  const counterEl = document.getElementById('stepCounter');
  if (labelEl) labelEl.textContent = STEP_LABELS[n - 1];
  if (counterEl) counterEl.textContent = `Step ${n} of ${TOTAL_STEPS}`;

  // Show OTP row after email is entered on step 1
  if (n === 1) updateOtpVisibility();

  currentStep = n;
}

async function nextStep(from) {
  if (!validate(from)) return;

  if (from === 1) {
    await requestPreSignupEmailVerification();
    return;
  }

  showStep(from + 1);
}

async function requestPreSignupEmailVerification() {
  const email = document.getElementById('email')?.value.trim() || '';
  const fullName = document.getElementById('fullName')?.value.trim() || '';

  const btn = document.querySelector('#panel1 .signup-btn');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Sending code…';
  }

  try {
    const res = await fetch('../../API/auth/send-signup-email-otp.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrf(),
        'Accept': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ email, full_name: fullName }),
    });

    const data = await res.json();

    if (data.success && data.otp_required) {
      showEmailVerification(data);
      return;
    }

    showErr(1, data.error || 'Unable to send the verification code.');
  } catch {
    showErr(1, 'Could not reach the email verification service. Please try again.');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Next →';
    }
  }
}

function goBack() {
  if (currentStep > 1) {
    showStep(currentStep - 1);
  } else {
    window.location.href = '../../index.php';
  }
}

// ── Validation ─────────────────────────────────────────────────────────────
function validate(step) {
  let msg = '';

  if (step === 1) {
    const name     = document.getElementById('fullName')?.value.trim() || '';
    const email    = document.getElementById('email')?.value.trim() || '';
    const password = document.getElementById('password')?.value || '';
    const confirm  = document.getElementById('confirmPass')?.value || '';
    if (!name)                       msg = 'Please enter your full name.';
    else if (!email)                 msg = 'Please enter your email or Student ID.';
    else if (password.length < 8)   msg = 'Password must be at least 8 characters.';
    else if (password !== confirm)   msg = 'Passwords do not match.';
  }

  if (step === 2) {
    const course = document.getElementById('course')?.value || '';
    const year   = document.getElementById('year')?.value || '';
    if (!course) msg = 'Please select your course.';
    else if (!year) msg = 'Please select your year level.';
  }

  if (step === 3) {
    const collab = getTagsByGroup('collab');
    const goal   = getTagsByGroup('goal');
    if (!collab.length && !goal.length) msg = 'Pick at least one collaboration style or goal.';
  }

  if (step === 4) {
    // Interests are optional — just move on
  }

  if (step === 5) {
    const terms = document.getElementById('terms')?.checked || false;
    if (!terms) msg = 'Please agree to the Terms & Privacy Policy.';
  }

  showErr(step, msg);
  return msg === '';
}

// ── Tag helpers ────────────────────────────────────────────────────────────
function toggleTag(el) {
  el.classList.toggle('selected');
}

function getTagsByGroup(group) {
  return [...document.querySelectorAll(`.tag.selected[data-group="${group}"]`)]
    .map(el => ({ slug: el.dataset.slug, label: el.textContent.trim() }));
}

function getAllSelectedTags() {
  return [...document.querySelectorAll('.tag.selected')]
    .map(el => ({ slug: el.dataset.slug, group: el.dataset.group, label: el.textContent.trim() }));
}

// ── Password strength ──────────────────────────────────────────────────────
function updateStrength(val) {
  let score = 0;
  if (val.length >= 8) score++;
  if (val.length >= 12) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  const fill = document.getElementById('strengthFill');
  if (!fill) return;
  fill.style.width  = (score / 5 * 100) + '%';
  fill.style.opacity = score > 0 ? '1' : '0';
}

function toggleEye(fieldId, iconId) {
  const input = document.getElementById(fieldId);
  const icon  = document.getElementById(iconId);
  if (!input || !icon) return;
  const isPass = input.type === 'password';
  input.type = isPass ? 'text' : 'password';
  icon.innerHTML = isPass
    ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`
    : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
}

// ── Hobby Builder ──────────────────────────────────────────────────────────
function renderHobbyBuilder() {
  const grid = document.getElementById('hobbyMainGrid');
  if (!grid) return;

  grid.innerHTML = HOBBY_DATA.map((h, idx) => `
    <div class="hobby-card ${activeHobbyCard === idx ? 'expanded' : ''}" data-idx="${idx}" onclick="toggleHobbyCard(${idx})">
      <div class="hobby-card-header">
        <span class="hobby-icon">${h.icon}</span>
        <span class="hobby-name">${h.hobby}</span>
        <span class="hobby-check" id="hcheck${idx}">${hobbies.some(x => x.hobby === h.hobby) ? '✓' : '+'}</span>
      </div>
      ${activeHobbyCard === idx ? renderHobbyExpanded(h, idx) : ''}
    </div>
  `).join('');
}

function renderHobbyExpanded(h, idx) {
  const existing = hobbies.find(x => x.hobby === h.hobby) || {};
  return `
    <div class="hobby-expanded" onclick="event.stopPropagation()">

      <div class="hobby-row-label">Genre / Sub-category</div>
      <div class="tags-wrap hobby-tags">
        ${h.genres.map(g => `
          <span class="tag hobby-genre-tag ${existing.genre === g ? 'selected' : ''}"
            data-hobby="${idx}" data-genre="${g}"
            onclick="selectGenre(${idx},'${g.replace(/'/g, "\\'")}',this)">
            ${g}
          </span>
        `).join('')}
      </div>

      <div class="hobby-row-label" id="titlesLabel${idx}" style="${existing.genre ? '' : 'display:none;'}">Specific Title / Focus</div>
      <div class="tags-wrap hobby-tags" id="titleTags${idx}">
        ${existing.genre && h.titles[existing.genre] ? h.titles[existing.genre].map(t => `
          <span class="tag hobby-title-tag ${existing.title === t ? 'selected' : ''}"
            data-hobby="${idx}" data-title="${t}"
            onclick="selectTitle(${idx},'${t.replace(/'/g, "\\'")}',this)">
            ${t}
          </span>
        `).join('') : ''}
      </div>

      <div class="hobby-row-label">Engagement</div>
      <div class="hobby-metrics">
        <div class="metric-item">
          <label class="metric-label">Style</label>
          <select class="metric-select" id="playstyle${idx}" onchange="updateHobbyField(${idx},'playstyle',this.value)">
            <option value="">—</option>
            ${h.playstyles.map(p => `<option value="${p}" ${existing.playstyle === p ? 'selected' : ''}>${p}</option>`).join('')}
          </select>
        </div>
        <div class="metric-item">
          <label class="metric-label">Experience</label>
          <select class="metric-select" id="experience${idx}" onchange="updateHobbyField(${idx},'experience',this.value)">
            <option value="">—</option>
            ${h.experiences.map(e => `<option value="${e}" ${existing.experience === e ? 'selected' : ''}>${e}</option>`).join('')}
          </select>
        </div>
        <div class="metric-item">
          <label class="metric-label">Hrs / month</label>
          <input type="number" class="metric-input" min="0" max="999" placeholder="0"
            value="${existing.hoursPerMonth || ''}"
            oninput="updateHobbyField(${idx},'hoursPerMonth',+this.value)"
            onclick="event.stopPropagation()">
        </div>
      </div>

      <button class="hobby-add-btn" onclick="saveHobby(${idx})">
        ${hobbies.some(x => x.hobby === h.hobby) ? '✓ Update' : '+ Add'}
      </button>

    </div>
  `;
}

function toggleHobbyCard(idx) {
  activeHobbyCard = activeHobbyCard === idx ? null : idx;
  renderHobbyBuilder();
}

function selectGenre(idx, genre, el) {
  // Deselect other genres in same card
  document.querySelectorAll(`.hobby-genre-tag[data-hobby="${idx}"]`).forEach(t => t.classList.remove('selected'));
  el.classList.add('selected');

  // Ensure hobby entry exists
  ensureHobbyEntry(idx);
  const h = HOBBY_DATA[idx];
  const entry = hobbies.find(x => x.hobby === h.hobby);
  if (entry) { entry.genre = genre; entry.title = ''; }

  // Render title tags
  const titlesContainer = document.getElementById('titleTags' + idx);
  const titlesLabel     = document.getElementById('titlesLabel' + idx);
  if (titlesLabel) titlesLabel.style.display = '';
  if (titlesContainer && h.titles[genre]) {
    titlesContainer.innerHTML = h.titles[genre].map(t => `
      <span class="tag hobby-title-tag" data-hobby="${idx}" data-title="${t}"
        onclick="selectTitle(${idx},'${t.replace(/'/g, "\\'")}',this)">${t}</span>
    `).join('');
  }
}

function selectTitle(idx, title, el) {
  document.querySelectorAll(`.hobby-title-tag[data-hobby="${idx}"]`).forEach(t => t.classList.remove('selected'));
  el.classList.add('selected');
  ensureHobbyEntry(idx);
  const entry = hobbies.find(x => x.hobby === HOBBY_DATA[idx].hobby);
  if (entry) entry.title = title;
}

function updateHobbyField(idx, field, value) {
  ensureHobbyEntry(idx);
  const entry = hobbies.find(x => x.hobby === HOBBY_DATA[idx].hobby);
  if (entry) entry[field] = value;
}

function ensureHobbyEntry(idx) {
  const hobbyName = HOBBY_DATA[idx].hobby;
  if (!hobbies.find(x => x.hobby === hobbyName)) {
    hobbies.push({ hobby: hobbyName, genre: '', title: '', hoursPerMonth: 0, playstyle: '', experience: '' });
  }
}

function saveHobby(idx) {
  ensureHobbyEntry(idx);
  activeHobbyCard = null;
  renderHobbyBuilder();
  renderHobbyChips();
}

function removeHobby(hobbyName) {
  hobbies = hobbies.filter(x => x.hobby !== hobbyName);
  renderHobbyBuilder();
  renderHobbyChips();
}

function renderHobbyChips() {
  const container  = document.getElementById('selectedHobbies');
  const chipsEl    = document.getElementById('hobbyChips');
  if (!container || !chipsEl) return;

  if (!hobbies.length) {
    container.style.display = 'none';
    return;
  }
  container.style.display = '';
  chipsEl.innerHTML = hobbies.map(h => `
    <div class="hobby-chip">
      <span class="hobby-chip-icon">${HOBBY_DATA.find(d => d.hobby === h.hobby)?.icon || '🎯'}</span>
      <div class="hobby-chip-info">
        <span class="hobby-chip-name">${h.hobby}</span>
        ${h.genre ? `<span class="hobby-chip-sub">${h.genre}${h.title ? ' · ' + h.title : ''}</span>` : ''}
      </div>
      <button class="hobby-chip-remove" onclick="removeHobby('${h.hobby}')" title="Remove">×</button>
    </div>
  `).join('');
}

// ── Year level mapping ──────────────────────────────────────────────────────
function mapYearLevel(yearStr) {
  const map = { '1st Year': 1, '2nd Year': 2, '3rd Year': 3, '4th Year': 4 };
  return map[yearStr] || 0;
}

// ── Submit ─────────────────────────────────────────────────────────────────
async function doSignup() {
  if (!validate(5)) return;
  setLoading(true);

  const tags   = getAllSelectedTags();
  const collab = tags.filter(t => t.group === 'collab').map(t => t.slug);
  const goals  = tags.filter(t => t.group === 'goal').map(t => t.slug);
  const avail  = tags.filter(t => t.group === 'avail').map(t => t.slug);
  const interests = tags.filter(t => ['tech','academic','creative'].includes(t.group)).map(t => t.slug);

  const payload = {
    full_name:    document.getElementById('fullName')?.value.trim() || '',
    email:        document.getElementById('email')?.value.trim() || '',
    password:     document.getElementById('password')?.value || '',
    course:       document.getElementById('course')?.value || '',
    year_level:   mapYearLevel(document.getElementById('year')?.value || ''),
    // Focus areas (step 3)
    collab_style: collab,
    goals,
    availability: avail,
    // Interests (step 4)
    interests,
    // Hobbies (step 5)
    hobbies,
    terms_agreed: document.getElementById('terms')?.checked || false,
    // Legacy fields for AuthService compatibility
    study_style:  collab.includes('solo-learning') ? 'solo' : collab.includes('team-projects') ? 'group' : 'mixed',
    primary_goal: goals[0] || '',
  };

  try {
    const res = await fetch('../../API/auth/signup.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (data.success && data.otp_required) {
      showEmailVerification(data);
    } else if (data.success) {
      const btn = document.getElementById('submitBtn');
      if (btn) {
        btn.textContent = '✓ Account Created!';
        btn.style.background    = 'linear-gradient(135deg, #0f9, #0cf)';
        btn.style.boxShadow     = '0 0 30px rgba(0,255,180,0.4)';
        btn.disabled = true;
      }
      setTimeout(() => { window.location.href = data.redirect || '../student/dashboard.php'; }, 900);
    } else {
      showErr(5, data.error || 'Registration failed. Please try again.');
      setLoading(false);
    }
  } catch {
    showErr(5, 'Network error. Please try again.');
    setLoading(false);
  }
}

// ── Signup email verification ──────────────────────────────────────────────
let verificationTimer = null;
let verificationBusy = false;

function showEmailVerification(data = {}) {
  const modal = document.getElementById('emailVerificationModal');
  const input = document.getElementById('signupOtpInput');
  const error = document.getElementById('signupOtpError');
  const hint = document.getElementById('signupOtpHint');

  if (!modal) return;

  const devCode = data.otp_debug || '';
  if (hint) {
    hint.textContent = devCode
      ? `DEV: code is ${devCode}`
      : data.mail_sent
        ? 'A 6-digit code was sent to your email.'
        : (data.mail_error || 'We could not send the code yet. Use Resend Code to try again.');
  }
  if (error) error.textContent = '';
  if (input) {
    input.value = '';
    input.focus();
  }

  modal.style.display = 'flex';
  startVerificationCountdown();
}

function startVerificationCountdown(seconds = 60) {
  const resend = document.getElementById('signupResendBtn');
  if (!resend) return;

  if (verificationTimer) clearInterval(verificationTimer);
  let remaining = seconds;
  resend.disabled = true;
  resend.textContent = `Resend Code (${remaining}s)`;

  verificationTimer = setInterval(() => {
    remaining -= 1;
    if (remaining <= 0) {
      clearInterval(verificationTimer);
      verificationTimer = null;
      resend.disabled = false;
      resend.textContent = 'Resend Code';
    } else {
      resend.textContent = `Resend Code (${remaining}s)`;
    }
  }, 1000);
}

async function verifySignupOtp() {
  if (verificationBusy) return;

  const input = document.getElementById('signupOtpInput');
  const error = document.getElementById('signupOtpError');
  const button = document.getElementById('signupVerifyBtn');
  const otp = (input?.value || '').replace(/\D/g, '');
  const email = document.getElementById('email')?.value.trim() || '';

  if (otp.length !== 6) {
    if (error) error.textContent = 'Enter the 6-digit verification code.';
    return;
  }

  verificationBusy = true;
  if (button) {
    button.disabled = true;
    button.textContent = 'Verifying…';
  }
  if (error) error.textContent = '';

  try {
    const res = await fetch('../../API/auth/verify-signup-email-otp.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrf(),
        'Accept': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ email, otp }),
    });
    const data = await res.json();

    if (data.success && data.verified) {
      if (button) button.textContent = '✓ Verified';
      const modal = document.getElementById('emailVerificationModal');
      if (modal) modal.style.display = 'none';
      startVerificationCountdown(0);
      showStep(2);
      return;
    }

    if (error) error.textContent = data.error || 'Verification failed. Please try again.';
  } catch {
    if (error) error.textContent = 'Network error. Please try again.';
  } finally {
    verificationBusy = false;
    if (button && button.textContent !== '✓ Verified') {
      button.disabled = false;
      button.textContent = 'Verify Email';
    }
  }
}

async function resendSignupOtp() {
  const resend = document.getElementById('signupResendBtn');
  const hint = document.getElementById('signupOtpHint');
  if (!resend || resend.disabled) return;

  resend.disabled = true;
  resend.textContent = 'Sending…';

  const email = document.getElementById('email')?.value.trim() || '';
  const fullName = document.getElementById('fullName')?.value.trim() || '';

  try {
    const res = await fetch('../../API/auth/send-signup-email-otp.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrf(),
        'Accept': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ email, full_name: fullName }),
    });
    const data = await res.json();

    if (data.success) {
      const devCode = data.otp_debug || '';
      if (hint) {
        hint.textContent = devCode
          ? `DEV: code is ${devCode}`
          : 'A new verification code was sent to your email.';
      }
      startVerificationCountdown();
    } else {
      if (hint) hint.textContent = data.error || 'Unable to resend the code.';
      resend.disabled = false;
      resend.textContent = 'Resend Code';
    }
  } catch {
    if (hint) hint.textContent = 'Network error. Please try again.';
    resend.disabled = false;
    resend.textContent = 'Resend Code';
  }
}

function closeEmailVerification() {
  // Deliberately do not destroy the pending signup state. The account remains
  // unverified until the code is accepted; closing only returns to the form.
  const modal = document.getElementById('emailVerificationModal');
  if (modal) modal.style.display = 'none';
  if (verificationTimer) {
    clearInterval(verificationTimer);
    verificationTimer = null;
  }
}

// ── DOMContentLoaded ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  showStep(1);
  renderHobbyBuilder();

  // OTP visibility on email input
  document.getElementById('email')?.addEventListener('input', updateOtpVisibility);

  // Focus highlight
  document.querySelectorAll('.input-row input, .input-row select').forEach(el => {
    el.addEventListener('focus', () => el.closest('.input-row').style.borderColor = 'rgba(255,45,117,0.5)');
    el.addEventListener('blur',  () => el.closest('.input-row').style.borderColor = 'rgba(255,255,255,0.06)');
  });

  initParticles();
});

// ── Particle canvas ────────────────────────────────────────────────────────
function initParticles() {
  const canvas = document.getElementById('particles');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
  resize();
  window.addEventListener('resize', resize, { passive: true });
  const particles = Array.from({ length: 55 }, () => ({
    x: Math.random() * window.innerWidth, y: Math.random() * window.innerHeight,
    r: Math.random() * 1.8 + 0.4,
    vx: (Math.random() - 0.5) * 0.35, vy: (Math.random() - 0.5) * 0.35,
    alpha: Math.random() * 0.5 + 0.15,
    color: Math.random() > 0.5 ? '255,45,117' : '168,85,247',
  }));
  function drawConnections() {
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const dist = Math.hypot(particles[i].x - particles[j].x, particles[i].y - particles[j].y);
        if (dist < 110) {
          ctx.beginPath(); ctx.moveTo(particles[i].x, particles[i].y);
          ctx.lineTo(particles[j].x, particles[j].y);
          ctx.strokeStyle = `rgba(255,45,117,${0.07 * (1 - dist / 110)})`; ctx.lineWidth = 0.7; ctx.stroke();
        }
      }
    }
  }
  function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawConnections();
    particles.forEach(p => {
      p.x += p.vx; p.y += p.vy;
      if (p.x < 0) p.x = canvas.width; if (p.x > canvas.width) p.x = 0;
      if (p.y < 0) p.y = canvas.height; if (p.y > canvas.height) p.y = 0;
      ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(${p.color},${p.alpha})`;
      ctx.shadowBlur = 6; ctx.shadowColor = `rgba(${p.color},0.5)`; ctx.fill(); ctx.shadowBlur = 0;
    });
    requestAnimationFrame(animate);
  }
  animate();
}

// ── Globals ────────────────────────────────────────────────────────────────
window.nextStep         = nextStep;
window.goBack           = goBack;
window.doSignup         = doSignup;
window.toggleEye        = toggleEye;
window.updateStrength   = updateStrength;
window.toggleTag        = toggleTag;
window.verifySignupOtp  = verifySignupOtp;
window.resendSignupOtp  = resendSignupOtp;
window.closeEmailVerification = closeEmailVerification;
window.toggleHobbyCard  = toggleHobbyCard;
window.selectGenre      = selectGenre;
window.selectTitle      = selectTitle;
window.updateHobbyField = updateHobbyField;
window.saveHobby        = saveHobby;
window.removeHobby      = removeHobby;
