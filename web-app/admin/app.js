let currentMatch = null;
let rewardsRefreshTimer = null;
let rewardItems = [];

const loginView = document.getElementById("loginView");
const panelView = document.getElementById("panelView");
const loginForm = document.getElementById("loginForm");
const adminUsername = document.getElementById("adminUsername");
const adminPassword = document.getElementById("adminPassword");
const loginMessage = document.getElementById("loginMessage");
const panelMessage = document.getElementById("panelMessage");
const logoutBtn = document.getElementById("logoutBtn");
const reloadBtn = document.getElementById("reloadBtn");
const reloadRewardsBtn = document.getElementById("reloadRewardsBtn");
const reloadRewardItemsBtn = document.getElementById("reloadRewardItemsBtn");
const saveRewardItemsBtn = document.getElementById("saveRewardItemsBtn");
const createBtn = document.getElementById("createBtn");
const openBtn = document.getElementById("openBtn");
const lockBtn = document.getElementById("lockBtn");
const payoutBtn = document.getElementById("payoutBtn");
const cancelBtn = document.getElementById("cancelBtn");
const matchTitle = document.getElementById("matchTitle");
const winnerSelect = document.getElementById("winnerSelect");

const matchSummary = document.getElementById("matchSummary");
const matchStatus = document.getElementById("matchStatus");
const totalPool = document.getElementById("totalPool");
const winnerText = document.getElementById("winnerText");
const grokoPool = document.getElementById("grokoPool");
const chadPool = document.getElementById("chadPool");
const grokoUsers = document.getElementById("grokoUsers");
const chadUsers = document.getElementById("chadUsers");
const betsList = document.getElementById("betsList");
const rewardOrdersList = document.getElementById("rewardOrdersList");
const rewardItemsForm = document.getElementById("rewardItemsForm");

loginForm.addEventListener("submit", login);
logoutBtn.addEventListener("click", logout);
reloadBtn.addEventListener("click", loadMatch);
reloadRewardsBtn.addEventListener("click", loadRewards);
reloadRewardItemsBtn.addEventListener("click", loadRewardItems);
saveRewardItemsBtn.addEventListener("click", saveRewardItems);
createBtn.addEventListener("click", () => runAction("create", { title: matchTitle.value.trim() }));
openBtn.addEventListener("click", () => runAction("open"));
lockBtn.addEventListener("click", () => runAction("lock"));
payoutBtn.addEventListener("click", () => runAction("payout", { winner: winnerSelect.value }));
cancelBtn.addEventListener("click", () => {
  if (confirm("Cancel this match and refund all unpaid bets?")) {
    runAction("cancel");
  }
});

boot();

async function apiRequest(url, options = {}) {
  const response = await fetch(url, options);
  const text = await response.text();

  try {
    return JSON.parse(text);
  } catch {
    throw new Error(text || "Invalid server response");
  }
}

async function boot() {
  try {
    const data = await apiRequest("../api/admin-status.php");

    if (data.loggedIn) {
      showPanel();
      await loadMatch();
      await loadRewards();
      await loadRewardItems();
    } else {
      showLogin();
    }
  } catch (error) {
    showLogin();
    loginMessage.textContent = error.message;
  }
}

async function login(event) {
  event.preventDefault();
  loginMessage.textContent = "";

  try {
    const data = await apiRequest("../api/admin-login.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        username: adminUsername.value.trim(),
        password: adminPassword.value,
      }),
    });

    if (!data.success) {
      loginMessage.textContent = data.message || "Login failed.";
      return;
    }

    adminPassword.value = "";
    showPanel();
    await loadMatch();
    await loadRewards();
    await loadRewardItems();
  } catch (error) {
    loginMessage.textContent = error.message;
  }
}

async function logout() {
  try {
    await apiRequest("../api/admin-logout.php");
  } finally {
    currentMatch = null;
    showLogin();
  }
}

async function loadMatch() {
  try {
    const data = await apiRequest(`../api/admin-match.php?t=${Date.now()}`);

    if (!data.success) {
      showPanelMessage(data.message || "Failed to load match.", true);
      return;
    }

    renderMatch(data);
    await loadRewards();
  } catch (error) {
    showPanelMessage(error.message, true);
  }
}

async function loadRewards() {
  try {
    const data = await apiRequest(`../api/admin-rewards.php?t=${Date.now()}`);

    if (!data.success) {
      rewardOrdersList.innerHTML = `<div class="bet-row">${escapeHtml(data.message || "Rewards failed.")}</div>`;
      return;
    }

    renderRewardOrders(data.orders || []);
  } catch (error) {
    rewardOrdersList.innerHTML = `<div class="bet-row">${escapeHtml(error.message)}</div>`;
  }
}

async function loadRewardItems() {
  try {
    const data = await apiRequest(`../api/admin-reward-items.php?t=${Date.now()}`);

    if (!data.success) {
      rewardItemsForm.innerHTML = `<div class="bet-row">${escapeHtml(data.message || "Reward items failed.")}</div>`;
      return;
    }

    rewardItems = data.items || [];
    renderRewardItems(rewardItems);
  } catch (error) {
    rewardItemsForm.innerHTML = `<div class="bet-row">${escapeHtml(error.message)}</div>`;
  }
}

async function saveRewardItems() {
  const items = rewardItems.map((item) => ({
    key: item.key,
    price: Number(document.querySelector(`[data-reward-price="${item.key}"]`)?.value || 0),
    stock: Number(document.querySelector(`[data-reward-stock="${item.key}"]`)?.value || 0),
  }));

  saveRewardItemsBtn.disabled = true;
  showPanelMessage("Saving reward items...");

  try {
    const data = await apiRequest("../api/admin-reward-items.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ items }),
    });

    if (!data.success) {
      showPanelMessage(data.message || "Reward save failed.", true);
      return;
    }

    rewardItems = data.items || [];
    renderRewardItems(rewardItems);
    await loadRewards();
    showPanelMessage(data.message || "Reward items saved.");
  } catch (error) {
    showPanelMessage(error.message, true);
  } finally {
    saveRewardItemsBtn.disabled = false;
  }
}

async function runAction(action, extra = {}) {
  const needsMatch = action !== "create";

  if (needsMatch && !currentMatch) {
    showPanelMessage("Create a match first.", true);
    return;
  }

  setActionsDisabled(true);
  showPanelMessage("Working...");

  try {
    const data = await apiRequest("../api/admin-match.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action,
        matchId: currentMatch?.matchId,
        ...extra,
      }),
    });

    if (!data.success) {
      showPanelMessage(data.message || "Action failed.", true);
      return;
    }

    renderMatch(data);
    showPanelMessage(data.message || "Done.");
  } catch (error) {
    showPanelMessage(error.message, true);
  } finally {
    createBtn.disabled = false;
    reloadBtn.disabled = false;
    updateActionState();
  }
}

function showLogin() {
  loginView.classList.remove("hidden");
  panelView.classList.add("hidden");

  if (rewardsRefreshTimer) {
    clearInterval(rewardsRefreshTimer);
    rewardsRefreshTimer = null;
  }
}

function showPanel() {
  loginView.classList.add("hidden");
  panelView.classList.remove("hidden");

  if (!rewardsRefreshTimer) {
    rewardsRefreshTimer = setInterval(async () => {
      await loadRewards();
      await loadRewardItems();
    }, 10000);
  }
}

function renderMatch(data) {
  currentMatch = data.match;

  if (!currentMatch) {
    matchSummary.textContent = "No match has been created yet.";
    matchStatus.textContent = "-";
    totalPool.textContent = "0";
    winnerText.textContent = "NONE";
    grokoPool.textContent = "0";
    chadPool.textContent = "0";
    grokoUsers.textContent = "0";
    chadUsers.textContent = "0";
    betsList.innerHTML = '<div class="bet-row">No bets yet.</div>';
    updateActionState();
    return;
  }

  matchSummary.textContent = `#${currentMatch.matchId} | ${currentMatch.title}`;
  matchStatus.textContent = formatStatus(currentMatch.status);
  totalPool.textContent = currentMatch.totalPool;
  winnerText.textContent = currentMatch.winner || "NONE";
  grokoPool.textContent = currentMatch.grokozillaPool;
  chadPool.textContent = currentMatch.chadgptPool;
  grokoUsers.textContent = currentMatch.grokozillaBetsCount;
  chadUsers.textContent = currentMatch.chadgptBetsCount;

  renderBets(data.bets || []);
  updateActionState();
}

function renderBets(bets) {
  if (!bets.length) {
    betsList.innerHTML = '<div class="bet-row">No bets yet.</div>';
    return;
  }

  betsList.innerHTML = bets.map((bet) => {
    const state = bet.refunded
      ? "Refunded"
      : bet.isWon === true
        ? `Won +${bet.payoutAmount}`
        : bet.isWon === false
          ? "Lost"
          : "Pending";

    return `
      <div class="bet-row">
        <strong>${escapeHtml(bet.username)}</strong>
        <span>${bet.amount} on ${bet.selectedRobot}</span>
        <small>${state}</small>
      </div>
    `;
  }).join("");
}

function renderRewardOrders(orders) {
  if (!orders.length) {
    rewardOrdersList.innerHTML = '<div class="bet-row">No reward requests yet.</div>';
    return;
  }

  rewardOrdersList.innerHTML = orders.map((order) => `
    <div class="bet-row">
      <strong>${escapeHtml(order.username)}</strong>
      <span>${escapeHtml(order.itemName)} | ${order.price} coins</span>
      <small>${escapeHtml(order.status)}</small>
      ${order.status === "pending" ? `
        <div class="reward-order-actions">
          <button type="button" class="complete-reward-btn" data-order-id="${order.orderId}">Confirm</button>
          <button type="button" class="cancel-reward-btn" data-order-id="${order.orderId}">Cancel</button>
        </div>
      ` : ""}
    </div>
  `).join("");

  document.querySelectorAll(".complete-reward-btn").forEach((button) => {
    button.addEventListener("click", () => updateRewardOrder(button.dataset.orderId, "deliver"));
  });

  document.querySelectorAll(".cancel-reward-btn").forEach((button) => {
    button.addEventListener("click", () => updateRewardOrder(button.dataset.orderId, "cancel"));
  });
}

async function updateRewardOrder(orderId, action) {
  showPanelMessage("Updating reward order...");

  try {
    const data = await apiRequest("../api/admin-rewards.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ orderId: Number(orderId), action }),
    });

    if (!data.success) {
      showPanelMessage(data.message || "Reward update failed.", true);
      return;
    }

    renderRewardOrders(data.orders || []);
    await loadRewardItems();
    showPanelMessage(data.message || "Reward order updated.");
  } catch (error) {
    showPanelMessage(error.message, true);
  }
}

function renderRewardItems(items) {
  if (!items.length) {
    rewardItemsForm.innerHTML = '<div class="bet-row">No reward items found.</div>';
    return;
  }

  rewardItemsForm.innerHTML = items.map((item) => `
    <div class="reward-item-row">
      <strong>${escapeHtml(item.name)}</strong>
      <label>
        Price
        <input data-reward-price="${escapeHtml(item.key)}" type="number" min="0" value="${item.price}" />
      </label>
      <label>
        Available Stock
        <input data-reward-stock="${escapeHtml(item.key)}" type="number" min="0" value="${item.stock}" />
      </label>
    </div>
  `).join("");
}

function updateActionState() {
  const status = currentMatch?.status;
  const canCreate = !currentMatch || ["paid", "cancelled"].includes(status);

  createBtn.disabled = !canCreate;
  openBtn.disabled = !currentMatch || !["created", "betting_locked"].includes(status);
  lockBtn.disabled = !currentMatch || status !== "betting_open";
  payoutBtn.disabled = !currentMatch || !["betting_locked", "finished"].includes(status);
  cancelBtn.disabled = !currentMatch || ["paid", "cancelled"].includes(status);
}

function setActionsDisabled(disabled) {
  [createBtn, openBtn, lockBtn, payoutBtn, cancelBtn, reloadBtn].forEach((button) => {
    button.disabled = disabled;
  });
}

function showPanelMessage(message, isError = false) {
  panelMessage.textContent = message;
  panelMessage.classList.toggle("error", isError);
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

  return map[status] || status || "-";
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
