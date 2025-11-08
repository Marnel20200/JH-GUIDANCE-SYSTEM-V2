<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Information</title>
  <link rel="stylesheet" href="studview.css">
</head>
<body>
  <div class="overlay"></div> 
  <div class="topbar">
  <a href="Admin-Dashboard.html" class="back-btn">← Back to Dashboard</a>
</div>


  <div class="profile-card">
    <div class="header">
      <img id="pfp" src="https://i.pinimg.com/originals/75/ae/6e/75ae6eeeeb590c066ec53b277b614ce3.jpg" alt="2x2">
      <div>
        <h1 id="fullName">Loading...</h1>
        <p><b>ID:</b> <span id="studentID"></span></p>
        <p><b>Year Level / Course:</b> <span id="yearCourse"></span></p>
      </div>
    </div>
    <hr>

    <div class="section">
      <h3>Personal Information</h3>
      <p><b>Birthday:</b> <span id="bday"></span></p>
      <p><b>Nationality:</b> <span id="nat"></span></p>
      <p><b>Religion:</b> <span id="relig"></span></p>
      <p><b>Cellphone Number:</b> <span id="phone"></span></p>
      <p><b>Civil Status:</b> <span id="civilStatus"></span></p>
    </div>

    <div class="section" id="marriedInfo" style="display:none;">
      <h3>Marriage Information</h3>
      <p><b>Spouse Name:</b> <span id="spouseName"></span></p>
      <p><b>Age:</b> <span id="spouseAge"></span></p>
      <p><b>Nationality:</b> <span id="spouseNationality"></span></p>
      <p><b>Children:</b> <span id="children"></span></p>
      <p><b>Date of Marriage:</b> <span id="marriageDate"></span></p>
    </div>

    <div class="section">
      <h3>Living Arrangement</h3>
      <p id="living"></p>
    </div>

    <div class="section">
      <h3>Financial Support</h3>
      <p id="support"></p>
    </div>

    <div class="section">
      <h3>Transportation</h3>
      <p id="transport"></p>
    </div>

    <div class="section">
      <h3>Emergency Contact</h3>
      <p><b>Name:</b> <span id="EmerName"></span></p>
      <p><b>Address:</b> <span id="EmerAdres"></span></p>
      <p><b>Contact:</b> <span id="EmerCon"></span></p>
    </div>
  </div>

  <script>
    // Mock DB (replace with backend later)
    const students = [
      {
        id: 1,
        name: "Mark, Marcera",
        studentID: "20230001",
        year: "1st Year",
        course: "BSIT",
        bday: "2005-01-12",
        nat: "Filipino",
        relig: "Catholic",
        phone: "09123456789",
        civilStatus: "Single",
        living: "Living at home with family",
        support: "Family",
        transport: "Jeepney",
        EmerName: "Maria Marcera",
        EmerAdres: "Pagadian City",
        EmerCon: "09987654321"
      },
      {
        id: 2,
        name: "Asutilla, John Marnell",
        studentID: "20230002",
        year: "2nd Year",
        course: "BSHM",
        bday: "2004-05-18",
        nat: "Filipino",
        relig: "Christian",
        phone: "09911223344",
        civilStatus: "Married",
        spouseName: "Wife Asutilla",
        spouseAge: "22",
        spouseNationality: "Filipino",
        children: "1",
        marriageDate: "2022-06-20",
        living: "Living with spouse",
        support: "Part-time Job",
        transport: "Tricycle",
        EmerName: "Marenelli Asutilla",
        EmerAdres: "Molave",
        EmerCon: "09112223344"
      },
      {
        id: 3,
        name: "Martel, Ivan",
        studentID: "20230003",
        year: "3rd Year",
        course: "BSN",
        bday: "2003-09-07",
        nat: "Filipino",
        relig: "Iglesia ni Cristo",
        phone: "09199887766",
        civilStatus: "Single",
        living: "Living in a boarding house",
        boardingAddress: "Zamboanga City",
        boardingContact: "09334445566",
        support: "Scholarship",
        transport: "Bus",
        EmerName: "Egg Martel",
        EmerAdres: "Dipolog City",
        EmerCon: "09887766554",
        createdAt: 1746508200000
      },
      {
        id: 4,
        name: "Dela Peña, Jerald",
        studentID: "20230004",
        year: "4th Year",
        course: "BSED-English",
        bday: "2002-02-25",
        nat: "Filipino",
        relig: "Christian",
        phone: "09175554433",
        civilStatus: "Single",
        living: "Living with relatives/guardians",
        relativesAddress: "Pagadian City",
        relativesContact: "09121113344",
        support: "Educational Plan",
        transport: "Bus",
        EmerName: "Esmeralda Dela Peña",
        EmerAdres: "Pagadian City",
        EmerCon: "09776655443",
        createdAt: 1746511800000
      },
      {
        id: 5,
        name: "Dave, Madrazo",
        studentID: "20230005",
        year: "1st Year",
        course: "BSEntrep",
        bday: "2005-07-14",
        nat: "Filipino",
        relig: "Christian",
        phone: "09229998877",
        civilStatus: "Single",
        living: "Living at home with family",
        support: "Family",
        transport: "Motorcycle",
        EmerName: "Davey Madrazo",
        EmerAdres: "Pagadian City",
        EmerCon: "09117775566",
        createdAt: 1746515400000
      },
      {
        id: 6,
        name: "Salvador, Vince Cyrus",
        studentID: "20230006",
        year: "2nd Year",
        course: "BSIT",
        bday: "2004-11-02",
        nat: "Filipino",
        relig: "Catholic",
        phone: "09334446677",
        civilStatus: "Single",
        living: "Living in a boarding house",
        boardingAddress: "Aurora, Zamboanga del Sur",
        boardingContact: "09553334422",
        support: "Scholarship",
        transport: "Jeepney",
        EmerName: "Idk Salvador",
        EmerAdres: "Aurora",
        EmerCon: "09443332211",
        createdAt: 1746519000000
      },
      {
        id: 7,
        name: "Adlao, Jhon Cries",
        studentID: "20230007",
        year: "3rd Year",
        course: "BSHM",
        bday: "2003-03-30",
        nat: "Filipino",
        relig: "Born Again Christian",
        phone: "09198887722",
        civilStatus: "Single",
        living: "Others (with friends)",
        othersSpecify: "Living in rented apartment",
        support: "Part-time Job",
        transport: "Motorcycle",
        EmerName: "Gabii Adlao",
        EmerAdres: "Pagadian City",
        EmerCon: "09123334455",
        createdAt: 1746522600000
      },
      {
        id: 8,
        name: "Idk, Name",
        studentID: "20230008",
        year: "4th Year",
        course: "BSIT",
        bday: "2002-10-11",
        nat: "Filipino",
        relig: "Catholic",
        phone: "09221114455",
        civilStatus: "Single",
        living: "Living with relatives/guardians",
        relativesAddress: "Ozamis City",
        relativesContact: "09447775566",
        support: "Family",
        transport: "Bus",
        EmerName: "Idontknow Name",
        EmerAdres: "Ozamis City",
        EmerCon: "09665554411",
        createdAt: 1746526200000
      },
      {
        id: 9,
        name: "Bruh, Idk",
        studentID: "20230009",
        year: "2nd Year",
        course: "BSN",
        bday: "2004-08-09",
        nat: "Filipino",
        relig: "Christian",
        phone: "09912221133",
        civilStatus: "Single",
        living: "Living at home with family",
        support: "Scholarship",
        transport: "Tricycle",
        EmerName: "Nevermind Idk",
        EmerAdres: "Molave",
        EmerCon: "09883334422",
        createdAt: 1746529800000
      },
      {
        id: 10,
        name: "Ragadio, Kriz John",
        studentID: "20230010",
        year: "3rd Year",
        course: "BSTP",
        bday: "2003-12-21",
        nat: "Filipino",
        relig: "Catholic",
        phone: "09114445577",
        civilStatus: "Single",
        living: "Living at home with family",
        support: "Family",
        transport: "Bus",
        EmerName: "Yes Ragadio",
        EmerAdres: "Pagadian City",
        EmerCon: "09223334455",
        createdAt: 1746533400000
      }
    ];

    const params = new URLSearchParams(window.location.search);
    const studentId = params.get("id");
    const student = students.find(s => s.id == studentId);

    if (student) {
      document.getElementById("fullName").textContent = student.name;
      document.getElementById("studentID").textContent = student.studentID;
      document.getElementById("yearCourse").textContent = `${student.year} / ${student.course}`;
      document.getElementById("bday").textContent = student.bday;
      document.getElementById("nat").textContent = student.nat;
      document.getElementById("relig").textContent = student.relig;
      document.getElementById("phone").textContent = student.phone;
      document.getElementById("civilStatus").textContent = student.civilStatus;
      document.getElementById("living").textContent = student.living;
      document.getElementById("support").textContent = student.support;
      document.getElementById("transport").textContent = student.transport;
      document.getElementById("EmerName").textContent = student.EmerName;
      document.getElementById("EmerAdres").textContent = student.EmerAdres;
      document.getElementById("EmerCon").textContent = student.EmerCon;

      // if married, show spouse details
      if (student.civilStatus.toLowerCase() === "married") {
        document.getElementById("marriedInfo").style.display = "block";
        document.getElementById("spouseName").textContent = student.spouseName;
        document.getElementById("spouseAge").textContent = student.spouseAge;
        document.getElementById("spouseNationality").textContent = student.spouseNationality;
        document.getElementById("children").textContent = student.children;
        document.getElementById("marriageDate").textContent = student.marriageDate;
      }
    } else {
      document.querySelector(".profile-card").innerHTML = "<p>No student found.</p>";
    }
  </script>
</body>
</html>
