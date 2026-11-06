provider "aws" {
  region = "us-east-1"
}

# Criação de uma instância EC2 simulada para hospedar o Docker da Barbearia
resource "aws_instance" "barbershop_server" {
  ami           = "ami-0c7217cdde317cfec" # Exemplo Amazon Linux 2023
  instance_type = "t3.micro"

  tags = {
    Name = "BarberShop-Cloud-Instance"
  }
}

# Banco de dados gerenciado RDS MySQL na Nuvem
resource "aws_db_instance" "barbershop_db" {
  allocated_storage    = 20
  engine               = "mysql"
  engine_version       = "8.0"
  instance_class       = "db.t3.micro"
  db_name              = "barbershop"
  username             = "admincloud"
  password             = "SenhaSeguraNuvem2026!"
  skip_final_snapshot  = true
}