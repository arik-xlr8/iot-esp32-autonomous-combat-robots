#include <ESP32Servo.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>

#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64

Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, -1);

// ADXL icin ikinci I2C hatti
TwoWire I2C_ADXL = TwoWire(1);
byte ADXL_ADDR = 0x53;

const int piezo1Pin = 34;
const int piezo2Pin = 35;

const int ldrPin = 36; // LDR AO -> GPIO36 / VP

const int ledPin = 2;

const int servo1Pin = 18;
const int servo2Pin = 19;

Servo servo1;
Servo servo2;

// HP
int robot1HP = 100;
int robot2HP = 100;
int damage = 5;

// Piezo
int threshold = 50;
int maxValidValue = 3800;

unsigned long lastReadTime = 0;
unsigned long readInterval = 2;

unsigned long hitCooldown = 350;
unsigned long lastHit1 = 0;
unsigned long lastHit2 = 0;

// LED
unsigned long ledOnTime = 0;
unsigned long ledDuration = 120;

// Servo
unsigned long lastServoMove = 0;
unsigned long lastServo2Move = 0;

unsigned long baseServoInterval = 300;
unsigned long minServoInterval = 175;

float robot2BaseBoost = 1.00;

int restAngle = 0;
int hitAngle = 40;

bool isHitting = false;
bool isHitting2 = false;

// Servo refresh
unsigned long lastServoRefresh = 0;
unsigned long servoRefreshInterval = 20;

int servo1CurrentAngle = restAngle;
int servo2CurrentAngle = restAngle;

// Rage
bool robot1Rage = false;
bool robot2Rage = false;

float robot1RageMultiplier = 1.0;
float robot2RageMultiplier = 1.0;

// LDR
unsigned long lastLdrRead = 0;
unsigned long ldrReadInterval = 200;

int ldrValue = 0;
int lightThreshold = 2000;

// Bazı LDR modüllerde ışık artınca değer artar, bazılarında düşer.
// Eğer ters çalışırsa bunu false yap.
bool lightValueIncreasesWithLight = true;

bool isBright = false;

float ldrBoostMultiplier = 1.35; // Aydınlık/karanlık avantaj çarpanı
float robot1LdrMultiplier = 1.0;
float robot2LdrMultiplier = 1.0;

// ADXL
bool adxlReady = false;

unsigned long lastAdxlRead = 0;
unsigned long adxlReadInterval = 30;

float maxAbsX = 0;
float maxAbsY = 0;
float maxAbsZ = 0;
float maxTotalAccel = 0;

void writeADXL(byte reg, byte value) {
  I2C_ADXL.beginTransmission(ADXL_ADDR);
  I2C_ADXL.write(reg);
  I2C_ADXL.write(value);
  I2C_ADXL.endTransmission();
}

bool readADXLRaw(int16_t &x, int16_t &y, int16_t &z) {
  I2C_ADXL.beginTransmission(ADXL_ADDR);
  I2C_ADXL.write(0x32); // DATAX0 register

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
  I2C_ADXL.setClock(100000); // daha stabil olsun diye 100kHz

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

  writeADXL(0x2D, 0x08); // measurement mode
  writeADXL(0x31, 0x0B); // full resolution, +/-16g

  return true;
}

void updateADXL() {
  if (!adxlReady) return;
  if (robot1HP <= 0 || robot2HP <= 0) return;

  int16_t rawX, rawY, rawZ;   // <-- eksik olan satır bu

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

  // Full resolution ADXL345 yaklaşık 3.9mg/LSB
  // 1g = 9.80665 m/s2
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
    // Aydınlıkta Robot 1 hızlanır
    robot1LdrMultiplier = ldrBoostMultiplier;
    robot2LdrMultiplier = 1.0;
  } else {
    // Karanlıkta Robot 2 hızlanır
    robot1LdrMultiplier = 1.0;
    robot2LdrMultiplier = ldrBoostMultiplier;
  }
}

void checkRageMode() {
  if (robot1HP < 75 && robot1HP > 0 && !robot1Rage) {
    robot1Rage = true;

    // 1.15 - 1.80 arasi random hiz carpani
    robot1RageMultiplier = random(115, 181) / 100.0;

    Serial.print("Robot1 RAGE x");
    Serial.println(robot1RageMultiplier);
  }

  if (robot2HP < 75 && robot2HP > 0 && !robot2Rage) {
    robot2Rage = true;

    // 1.15 - 1.80 arasi random hiz carpani
    robot2RageMultiplier = random(115, 181) / 100.0;

    Serial.print("Robot2 RAGE x");
    Serial.println(robot2RageMultiplier);
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

void drawBattleEndOLED() {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);

  if (robot1HP <= 0) {
    display.setCursor(0, 0);
    display.println("ROBOT 2 WINS!");
  } else if (robot2HP <= 0) {
    display.setCursor(0, 0);
    display.println("ROBOT 1 WINS!");
  }

  display.setCursor(0, 14);
  display.println("ADXL MAX VALUES:");

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
  display.print("m/s2");

  display.display();
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
  display.print("R1:");
  display.print(robot1HP);
  display.print(" ");

  if (robot1Rage) {
    display.print("RAGE x");
    display.print(robot1RageMultiplier, 1);
  } else {
    display.print("NORMAL");
  }

  display.setCursor(0, 22);
  display.print("R2:");
  display.print(robot2HP);
  display.print(" ");

  if (robot2Rage) {
    display.print("RAGE x");
    display.print(robot2RageMultiplier, 1);
  } else {
    display.print("NORMAL");
  }

  display.setCursor(0, 34);
  display.print(isBright ? "LIGHT -> R1 BOOST" : "DARK  -> R2 BOOST");

  display.setCursor(0, 44);
  display.print("LDR:");
  display.print(ldrValue);

  display.drawRect(0, 54, 60, 8, SSD1306_WHITE);
  display.fillRect(0, 54, map(robot1HP, 0, 100, 0, 60), 8, SSD1306_WHITE);

  display.drawRect(68, 54, 60, 8, SSD1306_WHITE);
  display.fillRect(68, 54, map(robot2HP, 0, 100, 0, 60), 8, SSD1306_WHITE);

  display.display();
}

void setup() {
  Serial.begin(115200);

  randomSeed(analogRead(32));

  pinMode(ledPin, OUTPUT);
  digitalWrite(ledPin, LOW);

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

  // ADXL ikinci I2C hatti: SDA 26, SCL 27
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

  // ADXL OKUMA - savas boyunca max X/Y/Z/Total toplar
  if (currentTime - lastAdxlRead >= adxlReadInterval) {
    lastAdxlRead = currentTime;
    updateADXL();
  }

  // LDR OKUMA
  if (currentTime - lastLdrRead >= ldrReadInterval) {
    lastLdrRead = currentTime;

    updateLDR();

    Serial.print("LDR: ");
    Serial.print(ldrValue);
    Serial.print(" | ");
    Serial.println(isBright ? "LIGHT -> R1 BOOST" : "DARK -> R2 BOOST");

    drawOLED();
  }

  // PIEZO OKUMA
  if (currentTime - lastReadTime >= readInterval) {
    lastReadTime = currentTime;

    int v1 = analogRead(piezo1Pin);
    int v2 = analogRead(piezo2Pin);

    // Robot 1 hit aldı
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

      Serial.print("Robot1 HIT: ");
      Serial.println(v1);

      digitalWrite(ledPin, HIGH);
      ledOnTime = currentTime;

      checkRageMode();
      drawOLED();
    }

    // Robot 2 hit aldı
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

      Serial.print("Robot2 HIT: ");
      Serial.println(v2);

      digitalWrite(ledPin, HIGH);
      ledOnTime = currentTime;

      checkRageMode();
      drawOLED();
    }
  }

  // LED kapat
  if (currentTime - ledOnTime >= ledDuration) {
    digitalWrite(ledPin, LOW);
  }

  // Can bittiyse servolar dursun
  if (robot1HP <= 0 || robot2HP <= 0) {
    servo1CurrentAngle = restAngle;
    servo2CurrentAngle = restAngle;

    servo1.write(restAngle);
    servo2.write(restAngle);

    drawBattleEndOLED();
    return;
  }

  // ROBOT 1 SERVO
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

  // ROBOT 2 SERVO
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

  // SERVO REFRESH
  if (currentTime - lastServoRefresh >= servoRefreshInterval) {
    lastServoRefresh = currentTime;

    servo1.write(servo1CurrentAngle);
    servo2.write(servo2CurrentAngle);
  }
}