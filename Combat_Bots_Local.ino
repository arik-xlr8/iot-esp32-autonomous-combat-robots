#include <ESP32Servo.h>
#include <Wire.h>
#include <WiFi.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <math.h>

#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64

Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, -1);

const char* ssid = "YOUR_WIFI_NAME";
const char* password = "YOUR_WIFI_PASSWORD";

bool cloudConnected = false;
unsigned long lastPublishTime = 0;
unsigned long cloudPublishInterval = 5000;

TwoWire I2C_ADXL = TwoWire(1);
byte ADXL_ADDR = 0x53;

const int piezo1Pin = 34;
const int piezo2Pin = 35;

const int ldrPin = 36;

const int servo1Pin = 18;
const int servo2Pin = 19;

Servo servo1;
Servo servo2;

const char* robot1Name = "CHADGPT";
const char* robot2Name = "GROKOZILLA";

int robot1HP = 100;
int robot2HP = 100;
int damage = 3;

int threshold = 20;
int maxValidValue = 3800;

int lastPiezo1Value = 0;
int lastPiezo2Value = 0;

unsigned long lastReadTime = 0;
unsigned long readInterval = 2;

unsigned long hitCooldown = 350;
unsigned long lastHit1 = 0;
unsigned long lastHit2 = 0;

unsigned long lastServoMove = 0;
unsigned long lastServo2Move = 0;

unsigned long baseServoInterval = 273;
unsigned long minServoInterval = 120;

float robot2BaseBoost = 1.00;

int restAngle = 0;
int hitAngle = 40;

bool isHitting = false;
bool isHitting2 = false;

unsigned long lastServoRefresh = 0;
unsigned long servoRefreshInterval = 20;

int servo1CurrentAngle = restAngle;
int servo2CurrentAngle = restAngle;

bool robot1RageSystemStarted = false;
bool robot2RageSystemStarted = false;

bool robot1Rage = false;
bool robot2Rage = false;

float robot1RageMultiplier = 1.0;
float robot2RageMultiplier = 1.0;

unsigned long robot1RagePhaseStartTime = 0;
unsigned long robot2RagePhaseStartTime = 0;

unsigned long rageDuration = 4000;

unsigned long lastLdrRead = 0;
unsigned long ldrReadInterval = 200;

int ldrValue = 0;
int lightThreshold = 2000;

bool lightValueIncreasesWithLight = true;

bool isBright = false;

float ldrBoostMultiplier = 1.35;
float robot1LdrMultiplier = 1.0;
float robot2LdrMultiplier = 1.0;

bool adxlReady = false;

unsigned long lastAdxlRead = 0;
unsigned long adxlReadInterval = 30;

float maxAbsX = 0;
float maxAbsY = 0;
float maxAbsZ = 0;
float maxTotalAccel = 0;

bool battleEnded = false;
unsigned long battleEndTime = 0;
unsigned long winnerScreenDuration = 3000;
unsigned long envDataScreenDuration = 5000;
unsigned long networkScreenDuration = 5000;

void setupWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  Serial.print("WiFi connecting");

  int attempts = 0;

  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(250);
    Serial.print(".");
    attempts++;
  }

  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("WiFi connected. IP: ");
    Serial.println(WiFi.localIP());
    Serial.print("RSSI: ");
    Serial.println(WiFi.RSSI());
  } else {
    Serial.println("WiFi not connected.");
  }
}

void updateCloudStatus(unsigned long currentTime) {
  if (WiFi.status() == WL_CONNECTED) {
    cloudConnected = true;

    if (currentTime - lastPublishTime >= cloudPublishInterval) {
      lastPublishTime = currentTime;
      Serial.println("Cloud demo publish updated.");
    }
  } else {
    cloudConnected = false;
  }
}

void writeADXL(byte reg, byte value) {
  I2C_ADXL.beginTransmission(ADXL_ADDR);
  I2C_ADXL.write(reg);
  I2C_ADXL.write(value);
  I2C_ADXL.endTransmission();
}

bool readADXLRaw(int16_t &x, int16_t &y, int16_t &z) {
  I2C_ADXL.beginTransmission(ADXL_ADDR);
  I2C_ADXL.write(0x32);

  if (I2C_ADXL.endTransmission(false) != 0) {
    return false;
  }

  if (I2C_ADXL.requestFrom(ADXL_ADDR, (byte)6) != 6) {
    return false;
  }

  x = (int16_t)(I2C_ADXL.read() | (I2C_ADXL.read() << 8));
  y = (int16_t)(I2C_ADXL.read() | (I2C_ADXL.read() << 8));
  z = (int16_t)(I2C_ADXL.read() | (I2C_ADXL.read() << 8));

  return true;
}

bool checkADXLAddress(byte addr) {
  I2C_ADXL.beginTransmission(addr);
  return I2C_ADXL.endTransmission() == 0;
}

bool initADXL() {
  I2C_ADXL.begin(26, 27);
  I2C_ADXL.setClock(100000);

  if (checkADXLAddress(0x53)) {
    ADXL_ADDR = 0x53;
    Serial.println("ADXL address: 0x53");
  } 
  else if (checkADXLAddress(0x1D)) {
    ADXL_ADDR = 0x1D;
    Serial.println("ADXL address: 0x1D");
  } 
  else {
    Serial.println("ADXL not found on 0x53 or 0x1D");
    return false;
  }

  writeADXL(0x2D, 0x08);
  writeADXL(0x31, 0x0B);

  return true;
}

void updateADXL() {
  if (!adxlReady) return;
  if (robot1HP <= 0 || robot2HP <= 0) return;

  int16_t rawX, rawY, rawZ;

  if (!readADXLRaw(rawX, rawY, rawZ)) {
    Serial.println("ADXL read failed");
    return;
  }

  Serial.print("ADXL raw X:");
  Serial.print(rawX);
  Serial.print(" Y:");
  Serial.print(rawY);
  Serial.print(" Z:");
  Serial.println(rawZ);

  float scale = 0.0039 * 9.80665;

  float x = rawX * scale;
  float y = rawY * scale;
  float z = rawZ * scale;

  float absX = fabs(x);
  float absY = fabs(y);
  float absZ = fabs(z);

  float total = sqrt(x * x + y * y + z * z);

  if (absX > maxAbsX) maxAbsX = absX;
  if (absY > maxAbsY) maxAbsY = absY;
  if (absZ > maxAbsZ) maxAbsZ = absZ;
  if (total > maxTotalAccel) maxTotalAccel = total;
}

int readLDR() {
  long sum = 0;

  for (int i = 0; i < 10; i++) {
    sum += analogRead(ldrPin);
    delayMicroseconds(500);
  }

  return sum / 10;
}

void updateLDR() {
  ldrValue = readLDR();

  if (lightValueIncreasesWithLight) {
    isBright = ldrValue > lightThreshold;
  } else {
    isBright = ldrValue < lightThreshold;
  }

  if (isBright) {
    robot1LdrMultiplier = ldrBoostMultiplier;
    robot2LdrMultiplier = ldrBoostMultiplier;
  } else {
    robot1LdrMultiplier = 1.0;
    robot2LdrMultiplier = 1.0;
  }
}

float getRandomRageMultiplier() {
  return random(150, 251) / 100.0;
}

void startRobot1RagePhase(unsigned long currentTime) {
  float newMultiplier = getRandomRageMultiplier();

  robot1Rage = true;

  if (newMultiplier > robot1RageMultiplier) {
    robot1RageMultiplier = newMultiplier;
    Serial.print("CHADGPT RAGE UPGRADED x");
    Serial.println(robot1RageMultiplier);
  } else {
    Serial.print("CHADGPT RAGE KEPT x");
    Serial.print(robot1RageMultiplier);
    Serial.print(" new:");
    Serial.println(newMultiplier);
  }

  robot1RagePhaseStartTime = currentTime;
}

void startRobot2RagePhase(unsigned long currentTime) {
  float newMultiplier = getRandomRageMultiplier();

  robot2Rage = true;

  if (newMultiplier > robot2RageMultiplier) {
    robot2RageMultiplier = newMultiplier;
    Serial.print("GROKOZILLA RAGE UPGRADED x");
    Serial.println(robot2RageMultiplier);
  } else {
    Serial.print("GROKOZILLA RAGE KEPT x");
    Serial.print(robot2RageMultiplier);
    Serial.print(" new:");
    Serial.println(newMultiplier);
  }

  robot2RagePhaseStartTime = currentTime;
}

void updateRageMode(unsigned long currentTime) {
  if (robot1HP < 75 && robot1HP > 0 && !robot1RageSystemStarted) {
    robot1RageSystemStarted = true;
    startRobot1RagePhase(currentTime);
  }

  if (robot2HP < 75 && robot2HP > 0 && !robot2RageSystemStarted) {
    robot2RageSystemStarted = true;
    startRobot2RagePhase(currentTime);
  }

  if (robot1RageSystemStarted && robot1HP > 0) {
    if (currentTime - robot1RagePhaseStartTime >= rageDuration) {
      startRobot1RagePhase(currentTime);
    }
  }

  if (robot2RageSystemStarted && robot2HP > 0) {
    if (currentTime - robot2RagePhaseStartTime >= rageDuration) {
      startRobot2RagePhase(currentTime);
    }
  }
}

unsigned long getRobot1ServoInterval() {
  float multiplier = robot1RageMultiplier * robot1LdrMultiplier;

  unsigned long interval = baseServoInterval / multiplier;

  if (interval < minServoInterval) {
    interval = minServoInterval;
  }

  return interval;
}

unsigned long getRobot2ServoInterval() {
  float multiplier = robot2RageMultiplier * robot2BaseBoost * robot2LdrMultiplier;

  unsigned long interval = baseServoInterval / multiplier;

  if (interval < minServoInterval) {
    interval = minServoInterval;
  }

  return interval;
}

void drawWinnerOLED() {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);

  display.setTextSize(2);
  display.setCursor(18, 4);
  display.println("WINNER");

  display.setTextSize(1);

  if (robot1HP <= 0) {
    display.setCursor(14, 34);
    display.println("GROKOZILLA WINS!");
  } else if (robot2HP <= 0) {
    display.setCursor(22, 34);
    display.println("CHADGPT WINS!");
  }

  display.setCursor(24, 54);
  display.println("FIGHT IS OVER");

  display.display();
}

void drawEnvDataOLED() {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);

  display.setCursor(0, 0);
  display.println("ENV DATA - ADXL345");

  display.setCursor(0, 12);
  display.println("Field shake max:");

  if (!adxlReady) {
    display.setCursor(0, 30);
    display.println("ADXL NOT FOUND");
    display.display();
    return;
  }

  display.setCursor(0, 26);
  display.print("X:");
  display.print(maxAbsX, 1);

  display.setCursor(64, 26);
  display.print("Y:");
  display.print(maxAbsY, 1);

  display.setCursor(0, 38);
  display.print("Z:");
  display.print(maxAbsZ, 1);

  display.setCursor(64, 38);
  display.print("T:");
  display.print(maxTotalAccel, 1);

  display.setCursor(0, 52);
  display.print("unit: m/s2");

  display.display();
}

void drawNetworkCloudOLED() {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);

  display.setCursor(0, 0);
  display.println("NETWORK STATUS");

  display.setCursor(0, 12);
  if (WiFi.status() == WL_CONNECTED) {
    display.println("WiFi: CONNECTED");

    display.setCursor(0, 24);
    display.print("IP:");
    display.println(WiFi.localIP());

    display.setCursor(0, 36);
    display.print("RSSI:");
    display.print(WiFi.RSSI());
    display.println(" dBm");
  } else {
    display.println("WiFi: DISCONNECTED");

    display.setCursor(0, 24);
    display.println("IP: -");

    display.setCursor(0, 36);
    display.println("RSSI: -");
  }

  display.setCursor(0, 48);
  display.print("Cloud:");
  display.print(cloudConnected ? "CONNECTED" : "OFFLINE");

  display.setCursor(0, 58);
  display.print("Last pub:");

  if (lastPublishTime == 0) {
    display.print("-");
  } else {
    unsigned long secondsAgo = (millis() - lastPublishTime) / 1000;
    display.print(secondsAgo);
    display.print("s ago");
  }

  display.display();
}

void drawBattleEndOLED() {
  unsigned long currentTime = millis();

  if (!battleEnded) {
    battleEnded = true;
    battleEndTime = currentTime;
  }

  unsigned long totalCycleDuration = winnerScreenDuration + envDataScreenDuration + networkScreenDuration;
  unsigned long elapsed = (currentTime - battleEndTime) % totalCycleDuration;

  if (elapsed < winnerScreenDuration) {
    drawWinnerOLED();
  } else if (elapsed < winnerScreenDuration + envDataScreenDuration) {
    drawEnvDataOLED();
  } else {
    drawNetworkCloudOLED();
  }
}

void drawOLED() {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);

  if (robot1HP <= 0 || robot2HP <= 0) {
    drawBattleEndOLED();
    return;
  }

  display.setTextSize(1);

  display.setCursor(0, 0);
  display.println("ROBOT BATTLE");

  display.setCursor(0, 10);
  display.print("CHADGPT:");
  display.print(robot1HP);
  display.print(" ");

  if (robot1Rage) {
    display.print("RAGE");
  } else {
    display.print("NORMAL");
  }

  display.setCursor(0, 22);
  display.print("GROKOZILLA:");
  display.print(robot2HP);
  display.print(" ");

  if (robot2Rage) {
    display.print("RAGE");
  } else {
    display.print("NORMAL");
  }

  display.setCursor(0, 34);
  display.print(isBright ? "LIGHT BOOST BOTH" : "NO LIGHT BOOST");

  display.setCursor(0, 44);
  display.print("L");
  display.print(ldrValue);
  display.print(" P");
  display.print(lastPiezo1Value);
  display.print("/");
  display.print(lastPiezo2Value);

  display.drawRect(0, 54, 60, 8, SSD1306_WHITE);
  display.fillRect(0, 54, map(robot2HP, 0, 100, 0, 60), 8, SSD1306_WHITE);

  display.drawRect(68, 54, 60, 8, SSD1306_WHITE);
  display.fillRect(68, 54, map(robot1HP, 0, 100, 0, 60), 8, SSD1306_WHITE);

  display.display();
}

void setup() {
  Serial.begin(115200);

  randomSeed(analogRead(32));

  setupWiFi();

  servo1.attach(servo1Pin, 500, 2400);
  servo2.attach(servo2Pin, 500, 2400);

  servo1CurrentAngle = restAngle;
  servo2CurrentAngle = restAngle;

  servo1.write(servo1CurrentAngle);
  servo2.write(servo2CurrentAngle);

  Wire.begin(21, 22);

  if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
    Serial.println("OLED bulunamadi!");
    while (true);
  }

  adxlReady = initADXL();

  if (adxlReady) {
    Serial.println("ADXL345 hazir!");
  } else {
    Serial.println("ADXL345 bulunamadi!");
  }

  updateLDR();
  drawOLED();
}

void loop() {
  unsigned long currentTime = millis();

  updateCloudStatus(currentTime);

  if (currentTime - lastAdxlRead >= adxlReadInterval) {
    lastAdxlRead = currentTime;
    updateADXL();
  }

  updateRageMode(currentTime);

  if (currentTime - lastLdrRead >= ldrReadInterval) {
    lastLdrRead = currentTime;

    updateLDR();

    Serial.print("LDR: ");
    Serial.print(ldrValue);
    Serial.print(" | ");
    Serial.println(isBright ? "LIGHT BOOST BOTH" : "NO LIGHT BOOST");

    drawOLED();
  }

  if (currentTime - lastReadTime >= readInterval) {
    lastReadTime = currentTime;

    int v1 = analogRead(piezo1Pin);
    int v2 = analogRead(piezo2Pin);

    lastPiezo1Value = v1;
    lastPiezo2Value = v2;

    if (
      robot1HP > 0 &&
      robot2HP > 0 &&
      v1 > threshold &&
      v1 < maxValidValue &&
      currentTime - lastHit1 > hitCooldown
    ) {
      lastHit1 = currentTime;

      robot1HP -= damage;
      if (robot1HP < 0) robot1HP = 0;

      Serial.print("CHADGPT HIT: ");
      Serial.println(v1);

      updateRageMode(currentTime);
      drawOLED();
    }

    if (
      robot1HP > 0 &&
      robot2HP > 0 &&
      v2 > threshold &&
      v2 < maxValidValue &&
      currentTime - lastHit2 > hitCooldown
    ) {
      lastHit2 = currentTime;

      robot2HP -= damage;
      if (robot2HP < 0) robot2HP = 0;

      Serial.print("GROKOZILLA HIT: ");
      Serial.println(v2);

      updateRageMode(currentTime);
      drawOLED();
    }
  }

  if (robot1HP <= 0 || robot2HP <= 0) {
    servo1CurrentAngle = restAngle;
    servo2CurrentAngle = restAngle;

    servo1.write(restAngle);
    servo2.write(restAngle);

    drawBattleEndOLED();
    return;
  }

  unsigned long servo1Interval = getRobot1ServoInterval();

  if (currentTime - lastServoMove >= servo1Interval) {
    lastServoMove = currentTime;

    if (isHitting) {
      servo1CurrentAngle = restAngle;
    } else {
      servo1CurrentAngle = hitAngle;
    }

    isHitting = !isHitting;
  }

  unsigned long servo2Interval = getRobot2ServoInterval();

  if (currentTime - lastServo2Move >= servo2Interval) {
    lastServo2Move = currentTime;

    if (isHitting2) {
      servo2CurrentAngle = restAngle;
    } else {
      servo2CurrentAngle = hitAngle;
    }

    isHitting2 = !isHitting2;
  }

  if (currentTime - lastServoRefresh >= servoRefreshInterval) {
    lastServoRefresh = currentTime;

    servo1.write(servo1CurrentAngle);
    servo2.write(servo2CurrentAngle);
  }
}