org.couchdb.user:cardiacdemo@shs.com
{
   "_id": "org.couchdb.user:n@1.com",
   "_rev": "1-5ff73d6c2413153fb71c8d69eede99bc",
   "password_scheme": "pbkdf2",
   "iterations": 10,
   "name": "n@1.com",
   "first_name": "kondal Rao",
   "last_name": "k",
   "email": "n@1.com",
   "alert_email": "cooldrs1846@gmail.com",
   "phone": "91235467890",
   "alert_phone": "9123405678",
   "specialization": "Cardiology",
   "city": "Hyderabad",
   "state": "Telangana",
   "country": "India",
   "dhp_code": "H-AesXNNWbsz",
   "hospital_affiliated": "SHS",
   "hospital_type": "Hospital",
   "hospital_phone": "",
   "hospital_email": "",
   "doctors_network": true,
   "critical_alerts_medium": "Email",
   "reports_medium": "Email",
   "random_code": "rEtghe",
   "admin": "No",
   "type": "user",
   "roles": [
   ],
   "level": "Doctor",
   "derived_key": "177e466128135029890b44a8a5010ac5b7140996",
   "salt": "f4dcce356454f9ee98c0ac70f0e2df1a"
}