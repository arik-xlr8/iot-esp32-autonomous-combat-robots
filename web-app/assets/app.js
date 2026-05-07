let currentUser = null;
let currentMatch = null;
let userBet = null;
let liveRefreshAbortController = null;
let pendingRefreshTimer = null;
let lastLiveEventId = 0;
let refreshInProgress = false;
let liveRefreshListening = false;
let matchViewEntered = false;
let renderedMatchId = null;
let lastLiveStatsData = null;
let waitingFallbackTimer = null;

const loginScreen = document.getElementById("loginScreen");
const loaderScreen = document.getElementById("loaderScreen");
const battleScreen = document.getElementById("battleScreen");
const waitingView = document.getElementById("waitingView");
const waitingText = document.getElementById("waitingText");
const enterMatchBtn = document.getElementById("enterMatchBtn");
const statusPanel = document.getElementById("statusPanel");
const arenaView = document.getElementById("arenaView");
const betsPanel = document.getElementById("betsPanel");

const usernameInput = document.getElementById("usernameInput");
const loginBtn = document.getElementById("loginBtn");
const loginError = document.getElementById("loginError");

const userName = document.getElementById("userName");
const coinBalance = document.getElementById("coinBalance");
const centerCoinBalance = document.getElementById("centerCoinBalance");

const matchStatus = document.getElementById("matchStatus");
const totalPool = document.getElementById("totalPool");
const winnerText = document.getElementById("winnerText");
const poolBowl = document.getElementById("poolBowl");
const chadPercent = document.getElementById("chadPercent");
const grokoPercent = document.getElementById("grokoPercent");
const chadPoolShare = document.getElementById("chadPoolShare");
const grokoPoolShare = document.getElementById("grokoPoolShare");

const grokoMultiplier = document.getElementById("grokoMultiplier");
const chadMultiplier = document.getElementById("chadMultiplier");

const grokoUsers = document.getElementById("grokoUsers");
const chadUsers = document.getElementById("chadUsers");

const grokoPool = document.getElementById("grokoPool");
const chadPool = document.getElementById("chadPool");

const betAmount = document.getElementById("betAmount");
const messageBox = document.getElementById("messageBox");
const userBetBox = document.getElementById("userBetBox");

const betsList = document.getElementById("betsList");

const resultPanel = document.getElementById("resultPanel");
const resultTitle = document.getElementById("resultTitle");
const resultDescription = document.getElementById("resultDescription");
const winnerAvatar = document.getElementById("winnerAvatar");
const winnerName = document.getElementById("winnerName");

const logoutBtn = document.getElementById("logoutBtn");
const rewardsBtn = document.getElementById("rewardsBtn");
const rewardsModal = document.getElementById("rewardsModal");
const rewardsBackdrop = document.getElementById("rewardsBackdrop");
const closeRewardsBtn = document.getElementById("closeRewardsBtn");
const rewardsMessage = document.getElementById("rewardsMessage");
const infoBtn = document.getElementById("infoBtn");
const infoModal = document.getElementById("infoModal");
const infoBackdrop = document.getElementById("infoBackdrop");
const closeInfoBtn = document.getElementById("closeInfoBtn");

loginBtn.addEventListener("click", login);
logoutBtn.addEventListener("click", logout);
rewardsBtn.addEventListener("click", openRewards);
rewardsBackdrop.addEventListener("click", closeRewards);
closeRewardsBtn.addEventListener("click", closeRewards);
infoBtn.addEventListener("click", openInfo);
infoBackdrop.addEventListener("click", closeInfo);
closeInfoBtn.addEventListener("click", closeInfo);
enterMatchBtn.addEventListener("click", enterMatchView);

usernameInput.addEventListener("keydown", (event) => {
  if (event.key === "Enter") {
    login();
  }
});

document.querySelectorAll(".bet-btn").forEach((button) => {
  button.addEventListener("click", () => {
    placeBet(button.dataset.robot);
  });
});

document.querySelectorAll(".reward-buy-btn").forEach((button) => {
  button.addEventListener("click", () => buyReward(button.dataset.item));
});

restoreSession();

async function apiRequest(url, options = {}) {
  const response = await fetch(url, options);
  const text = await response.text();

  try {
    return JSON.parse(text);
  } catch {
    throw new Error(text || "Invalid server response");
  }
}

async function login() {
  loginError.textContent = '';

  const username = usernameInput.value.trim();

  if (!username) {
    loginError.textContent = 'Please enter your name.';
    return;
  }

  try {
    const data = await apiRequest('api/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username })
    });

    if (!data.success) {
      loginError.textContent = data.message || 'Login failed.';
      return;
    }

    currentUser = data.user;
    updateUserUI();

    await enterBattleScreen();

  } catch (error) {
    loginError.textContent = error.message;
  }
}

async function restoreSession() {
  try {
    const data = await apiRequest(`api/get-live-stats.php?t=${Date.now()}`);

    if (!data.success) {
      showLoginScreen();
      return;
    }

    if (data.user) {
      currentUser = data.user;
      updateUserUI();
    }

    showBattleScreen();
    await initializeLiveRefreshState();
    startLiveRefresh(false);

    currentMatch = data.match;
    userBet = data.userBet;
    lastLiveStatsData = data;
    renderLiveStats(data);
  } catch {
    showLoginScreen();
  }
}

async function enterBattleScreen() {
  showBattleScreen();
  await initializeLiveRefreshState();
  startLiveRefresh(false);
  await refreshLiveStats();
}

async function loadActiveMatch() {
  const data = await apiRequest('api/get-active-match.php');

  if (!data.success) {
    showMessage(data.message || 'Failed to load match.');
    return;
  }

  currentUser = data.user;
  currentMatch = data.match;
  userBet = data.userBet;

  updateUserUI();

  if (!data.match) {
    matchStatus.textContent = 'No active match';
    showMessage('No active match found.');
    setBetButtonsDisabled(true);
    return;
  }

  matchStatus.textContent = formatStatus(data.match.status);
  totalPool.textContent = data.match.totalPool ?? 0;
  winnerText.textContent = data.match.winner || 'NONE';

  renderUserBet(data.userBet);
}

function startLiveRefresh(initialize = true) {
  stopLiveRefresh();

  liveRefreshListening = true;
  liveRefreshAbortController = new AbortController();
  listenForLiveRefresh(initialize);
}

function stopLiveRefresh() {
  liveRefreshListening = false;

  if (liveRefreshAbortController) {
    liveRefreshAbortController.abort();
    liveRefreshAbortController = null;
  }

  if (pendingRefreshTimer) {
    clearTimeout(pendingRefreshTimer);
    pendingRefreshTimer = null;
  }
}

async function initializeLiveRefreshState() {
  try {
    const data = await apiRequest(`api/live-refresh-state.php?t=${Date.now()}`);

    if (!data.success || !data.state) {
      return;
    }

    const eventId = Number(data.state.eventId || 0);
    lastLiveEventId = eventId;
  } catch (error) {
    showMessage(error.message);
  }
}

async function listenForLiveRefresh(initialize = true) {
  if (initialize) {
    await initializeLiveRefreshState();
  }

  while (liveRefreshListening) {
    try {
      const data = await apiRequest(
        `api/wait-live-refresh.php?lastEventId=${lastLiveEventId}&t=${Date.now()}`,
        { signal: liveRefreshAbortController?.signal },
      );

      if (!liveRefreshListening) {
        return;
      }

      if (data.success && data.changed && data.state) {
        handleLiveRefreshEvent(data.state);
      }
    } catch (error) {
      if (!liveRefreshListening || error.name === "AbortError") {
        return;
      }

      showMessage(error.message);
      await wait(2000);
    }
  }
}

function handleLiveRefreshEvent(state) {
  const eventId = Number(state.eventId || 0);

  if (eventId <= lastLiveEventId) {
    return;
  }

  lastLiveEventId = eventId;
  scheduleLiveStatsRefresh(Number(state.dueAtMs || 0));
}

function scheduleLiveStatsRefresh(dueAtMs = 0) {
  if (pendingRefreshTimer) {
    clearTimeout(pendingRefreshTimer);
  }

  const delayMs = Math.max(0, dueAtMs - Date.now());
  pendingRefreshTimer = setTimeout(() => {
    pendingRefreshTimer = null;
    refreshLiveStats();
  }, delayMs);
}

async function refreshLiveStats() {
  if (refreshInProgress) {
    return;
  }

  refreshInProgress = true;

  try {
    const data = await apiRequest('api/get-live-stats.php');

    if (!data.success) {
      showMessage(data.message || 'Live stats failed.');
      return;
    }

    if (data.user) {
      currentUser = data.user;
      updateUserUI();
    }

    currentMatch = data.match;
    userBet = data.userBet;
    lastLiveStatsData = data;

    renderLiveStats(data);

  } catch (error) {
    showMessage(error.message);
  } finally {
    refreshInProgress = false;
  }
}

async function placeBet(robot) {
  const amount = Number(betAmount.value);

  if (!amount || amount <= 0) {
    showMessage("Enter a valid coin amount.");
    return;
  }

  try {
    const data = await apiRequest("api/place-bet.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        selectedRobot: robot,
        amount,
      }),
    });

    if (!data.success) {
      showMessage(data.message || "Bet failed.");
      return;
    }

    currentUser = data.user;

    showMessage(`${amount} coins placed on ${robot}.`);
    updateUserUI();

    if (data.refresh) {
      lastLiveEventId = Math.max(lastLiveEventId, Number(data.refresh.eventId || 0));
      scheduleLiveStatsRefresh(Number(data.refresh.dueAtMs || 0));
    }
  } catch (error) {
    showMessage(error.message);
  }
}

function updateUserUI() {
  if (!currentUser) return;

  userName.textContent = currentUser.username;
  coinBalance.textContent = currentUser.coinBalance;

  if (centerCoinBalance) {
    centerCoinBalance.textContent = currentUser.coinBalance;
  }
}

function renderMatch(data) {
  if (!data.match) {
    matchStatus.textContent = "No active match";
    showMessage("No active match found.");
    setBetButtonsDisabled(true);
    return;
  }

  renderLiveStats({
    match: data.match,
    userBet: data.userBet,
    bets: [],
  });
}

function renderLiveStats(data) {
  const match = data.match;

  if (match?.matchId && match.matchId !== renderedMatchId) {
    renderedMatchId = match.matchId;
    matchViewEntered = false;
  }

  if (!match) {
    renderedMatchId = null;
    matchViewEntered = false;
    showWaitingView("Waiting for the next match...", false);

    matchStatus.textContent = "No active match";
    totalPool.textContent = "0";
    winnerText.textContent = "NONE";

    chadMultiplier.textContent = "0.00x";
    grokoMultiplier.textContent = "0.00x";

    chadUsers.textContent = "0";
    grokoUsers.textContent = "0";

    chadPool.textContent = "0";
    grokoPool.textContent = "0";
    renderPoolVisual(0, 0);

    betsList.innerHTML = '<div class="bet-row">No active match.</div>';
    userBetBox.classList.add("hidden");
    userBetBox.textContent = "";

    setBetButtonsDisabled(true);
    return;
    }

  if (match.status === "created" && !matchViewEntered) {
    showWaitingView("Maç hazır. Bahisler henüz açılmadı.", true, "Maça git");
    setBetButtonsDisabled(true);
    return;
  }

  if (match.status === "betting_open" && !matchViewEntered && !data.userBet) {
    showWaitingView("Bahisler açıldı. Arenaya girip tahminini yapabilirsin.", true, "Bahisler açıldı");
    return;
  }

  showMatchView();

  matchStatus.textContent = formatStatus(match.status);
  totalPool.textContent = match.totalPool;
  winnerText.textContent = match.winner || "NONE";

  chadMultiplier.textContent = `${Number(match.multipliers?.ChadGPT || 0).toFixed(2)}x`;
  grokoMultiplier.textContent = `${Number(match.multipliers?.GROKOZILLA || 0).toFixed(2)}x`;

  chadUsers.textContent =
    match.breakdown?.ChadGPT?.users ?? match.chadgptBetsCount ?? 0;
  grokoUsers.textContent =
    match.breakdown?.GROKOZILLA?.users ?? match.grokozillaBetsCount ?? 0;

  chadPool.textContent =
    match.breakdown?.ChadGPT?.coins ?? match.chadgptPool ?? 0;
  grokoPool.textContent =
    match.breakdown?.GROKOZILLA?.coins ?? match.grokozillaPool ?? 0;
  renderPoolVisual(Number(chadPool.textContent || 0), Number(grokoPool.textContent || 0));

  renderUserBet(data.userBet);
  renderBets(data.bets || []);

  const bettingOpen = match.status === "betting_open";
  const alreadyBet = Boolean(data.userBet);

  setBetButtonsDisabled(!bettingOpen || alreadyBet);

  if (match.winner && match.winner !== "NONE") {
    showResult(match.winner, data.userBet);
  } else {
    resultPanel.classList.add("hidden");
  }
}

function renderUserBet(bet) {
  if (!bet) {
    userBetBox.classList.add("hidden");
    userBetBox.textContent = "";
    return;
  }

  userBetBox.classList.remove("hidden");

  let resultText = "";

  if (bet.isWon === true) {
    resultText = ` | Won: +${bet.payoutAmount} coins`;
  } else if (bet.isWon === false) {
    resultText = " | Lost";
  } else if (bet.refunded) {
    resultText = " | Refunded";
  }

  userBetBox.textContent = `Your bet: ${bet.amount} coins on ${bet.selectedRobot} at ${Number(bet.multiplierAtBet).toFixed(2)}x${resultText}`;
}

function renderBets(bets) {
  if (!bets.length) {
    betsList.innerHTML = '<div class="bet-row">No bets yet.</div>';
    return;
  }

  betsList.innerHTML = bets
    .map(
      (bet) => `
    <div class="bet-row">
      <strong>${escapeHtml(bet.username)}</strong>
      <span>${bet.amount} coins on ${bet.selectedRobot}</span>
    </div>
  `,
    )
    .join("");
}

function showResult(winner, bet) {
  resultPanel.classList.remove("hidden");

  resultTitle.textContent = `${winner} won!`;
  winnerName.textContent = winner;

  if (winner === "ChadGPT") {
    winnerAvatar.textContent = "C";
    winnerAvatar.style.background = "#ef4444";
    winnerAvatar.style.color = "#450a0a";
  } else {
    winnerAvatar.textContent = "G";
    winnerAvatar.style.background = "#22c55e";
    winnerAvatar.style.color = "#052e16";
  }

  if (!bet) {
    resultDescription.textContent = "You did not place a bet in this match.";
  } else if (bet.selectedRobot === winner) {
    resultDescription.textContent = `Your prediction was correct. Payout: ${bet.payoutAmount ?? "pending"} coins.`;
  } else {
    resultDescription.textContent = "Your prediction lost this round.";
  }
}

function setBetButtonsDisabled(disabled) {
  document.querySelectorAll(".bet-btn").forEach((button) => {
    button.disabled = disabled;
  });
}

function showMessage(message) {
  messageBox.textContent = message;
}

function formatStatus(status) {
  const map = {
    created: "Created",
    betting_open: "Betting Open",
    betting_locked: "Betting Locked",
    finished: "Finished",
    paid: "Paid",
    cancelled: "Cancelled",
  };

  return map[status] || status;
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function renderPoolVisual(chadCoins, grokoCoins) {
  const totalCoins = chadCoins + grokoCoins;
  const chadRatio = totalCoins > 0 ? chadCoins / totalCoins : 0;
  const chadPct = Math.round(chadRatio * 100);
  const grokoPct = totalCoins > 0 ? 100 - chadPct : 0;

  chadPercent.textContent = `${chadPct}%`;
  grokoPercent.textContent = `${grokoPct}%`;

  if (chadPoolShare && grokoPoolShare) {
    chadPoolShare.textContent = `${chadPct}%`;
    grokoPoolShare.textContent = `${grokoPct}%`;
  }

  if (poolBowl) {
    poolBowl.style.setProperty("--chad-fill", `${chadPct}%`);
    poolBowl.style.setProperty("--groko-fill", `${grokoPct}%`);
  }
}

async function openRewards() {
  rewardsModal.classList.remove("hidden");
  rewardsMessage.textContent = "";
  await loadRewards();
}

function closeRewards() {
  rewardsModal.classList.add("hidden");
}

function openInfo() {
  infoModal.classList.remove("hidden");
}

function closeInfo() {
  infoModal.classList.add("hidden");
}

async function loadRewards() {
  try {
    const data = await apiRequest(`api/rewards.php?t=${Date.now()}`);

    if (!data.success) {
      rewardsMessage.textContent = data.message || "Rewards failed.";
      return;
    }

    renderRewards(data.items || []);
  } catch (error) {
    rewardsMessage.textContent = error.message;
  }
}

async function buyReward(itemKey) {
  rewardsMessage.textContent = "Processing...";
  setRewardButtonsDisabled(true);

  try {
    const data = await apiRequest("api/rewards.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ itemKey }),
    });

    if (!data.success) {
      rewardsMessage.textContent = data.message || "Purchase failed.";
      return;
    }

    if (data.user) {
      currentUser = data.user;
      updateUserUI();
    }

    renderRewards(data.items || []);
    rewardsMessage.textContent = data.message || "Purchased.";
  } catch (error) {
    rewardsMessage.textContent = error.message;
  } finally {
    setRewardButtonsDisabled(false);
  }
}

function renderRewards(items) {
  items.forEach((item) => {
    const stockEl = document.getElementById(`${item.key}Stock`);
    const priceEl = document.getElementById(`${item.key}Price`);
    const button = document.querySelector(`.reward-buy-btn[data-item="${item.key}"]`);

    if (stockEl) {
      stockEl.textContent = `${item.remaining} left`;
    }

    if (priceEl) {
      priceEl.textContent = item.price;
    }

    if (button) {
      button.disabled = item.remaining <= 0;
      button.textContent = item.remaining <= 0 ? "Sold out" : "Buy";
    }
  });
}

function setRewardButtonsDisabled(disabled) {
  document.querySelectorAll(".reward-buy-btn").forEach((button) => {
    button.disabled = disabled;
  });
}

function showWaitingView(message, showEnterButton, buttonText = "Maça git") {
  waitingView.classList.remove("hidden");
  statusPanel.classList.add("hidden");
  arenaView.classList.add("hidden");
  betsPanel.classList.add("hidden");
  resultPanel.classList.add("hidden");

  waitingText.textContent = message;
  enterMatchBtn.textContent = buttonText;
  enterMatchBtn.classList.toggle("hidden", !showEnterButton);
  startWaitingFallbackRefresh();
}

function showMatchView() {
  waitingView.classList.add("hidden");
  statusPanel.classList.remove("hidden");
  arenaView.classList.remove("hidden");
  betsPanel.classList.remove("hidden");
  stopWaitingFallbackRefresh();
}

function enterMatchView() {
  matchViewEntered = true;
  showMatchView();

  if (lastLiveStatsData) {
    renderLiveStats(lastLiveStatsData);
  }
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function showLoginScreen() {
  stopLiveRefresh();
  stopWaitingFallbackRefresh();
  loaderScreen.classList.add("hidden");
  battleScreen.classList.add("hidden");
  loginScreen.classList.remove("hidden");
}

function showBattleScreen() {
  loaderScreen.classList.add("hidden");
  loginScreen.classList.add("hidden");
  battleScreen.classList.remove("hidden");
}

function startWaitingFallbackRefresh() {
  if (waitingFallbackTimer) {
    return;
  }

  waitingFallbackTimer = setInterval(() => {
    if (!battleScreen.classList.contains("hidden") && !waitingView.classList.contains("hidden")) {
      refreshLiveStats();
    }
  }, 10000);
}

function stopWaitingFallbackRefresh() {
  if (!waitingFallbackTimer) {
    return;
  }

  clearInterval(waitingFallbackTimer);
  waitingFallbackTimer = null;
}

async function logout() {
  try {
    await apiRequest("api/logout.php");

    currentUser = null;
    currentMatch = null;
    userBet = null;
    lastLiveStatsData = null;
    matchViewEntered = false;
    renderedMatchId = null;

    stopLiveRefresh();
    stopWaitingFallbackRefresh();

    usernameInput.value = "";
    loginError.textContent = "";
    messageBox.textContent = "";
    userBetBox.textContent = "";
    userBetBox.classList.add("hidden");
    betsList.innerHTML = "";

    showLoginScreen();

    // Cache / eski state kalmasın diye küçük garanti
    setTimeout(() => {
      window.location.reload();
    }, 150);
  } catch (error) {
    showMessage(error.message);
  }
}
